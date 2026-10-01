<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Logger;
use App\Core\Uuid;

/**
 * Long-term offline export/import.
 *
 * Produces a gzip-compressed tar archive that bundles, per document version:
 * original file (Base64), full metadata, chunks (with stable chunk/vector ids),
 * Milvus vectors, collection schema, model configuration, jobs, a manifest and
 * a SHA-256 checksum manifest — so a complete, ID-preserving rebuild is possible
 * on another system without the original files or network access.
 */
final class ExportService
{
    public const FORMAT_VERSION = '1.0';

    public function exportRoot(): string
    {
        return Config::string('EXPORT_ROOT', '/srv/data/exports');
    }

    /**
     * Create an export of all stored document versions.
     *
     * @return array<string,mixed>
     */
    public function create(): array
    {
        $exportId = Uuid::v4();
        $root = $this->exportRoot();
        if (!is_dir($root)) {
            @mkdir($root, 0755, true);
        }
        $tmp = $root . '/.tmp_' . $exportId;
        $this->ensureDir($tmp);

        $documents = Db::fetchAll('SELECT * FROM documents ORDER BY id');
        $jobs = Db::fetchAll('SELECT * FROM jobs ORDER BY id');
        $models = Db::fetchAll('SELECT * FROM embedding_models ORDER BY id');

        $manifest = [
            'export_format_version' => self::FORMAT_VERSION,
            'application_version' => Config::string('APP_VERSION', '0.0.0'),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'source_system' => Config::string('APP_NAME', 'docvecwizard'),
            'export_id' => $exportId,
            'compression' => 'gzip',
            'checksums' => ['algorithm' => 'SHA-256'],
            'documents' => 0,
            'document_versions' => 0,
            'chunks' => 0,
            'vectors' => 0,
            'collections' => 0,
            'original_bytes' => 0,
            'base64_bytes' => 0,
            'embedding_models' => [],
        ];

        // ---- Milvus collection schema (best effort) ----
        $milvus = new MilvusClient();
        $collections = [];
        $vectorsExported = true;
        try {
            foreach ($milvus->listCollections() as $collection) {
                $collections[$collection] = [
                    'describe' => $milvus->describeCollection($collection),
                    'stats' => $milvus->collectionStats($collection),
                ];
            }
        } catch (\Throwable) {
            $vectorsExported = false;
        }

        // ---- Per-version payloads ----
        $this->ensureDir($tmp . '/documents');
        $chunkTotal = 0;
        $vectorTotal = 0;
        $originalBytes = 0;
        $base64Bytes = 0;
        $seenDocuments = [];
        foreach ($documents as $doc) {
            $documentId = (string) $doc['document_id'];
            $versionId = (string) $doc['document_version_id'];
            if (!isset($seenDocuments[$documentId])) {
                $seenDocuments[$documentId] = true;
            }

            $blob = Db::fetchOne('SELECT * FROM document_blobs WHERE document_version_id = ? LIMIT 1', [$versionId]);
            $chunks = Db::fetchAll('SELECT * FROM document_chunks WHERE document_id = ? ORDER BY chunk_index ASC', [$doc['id']]);
            $chunkTotal += count($chunks);
            $originalBytes += (int) ($blob['file_size'] ?? $doc['file_size']);
            $base64Bytes += is_string($blob['base64_data'] ?? null) ? strlen((string) $blob['base64_data']) : 0;

            $dir = $tmp . '/documents/' . $versionId;
            $this->ensureDir($dir);

            file_put_contents($dir . '/metadata.json', json_encode($this->docMetadata($doc, $blob), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            file_put_contents($dir . '/original.b64', (string) ($blob['base64_data'] ?? ''));
            file_put_contents($dir . '/checksum.sha256', (string) ($blob['sha256'] ?? $doc['file_hash']));

            $chunkRows = [];
            foreach ($chunks as $chunk) {
                $chunkRows[] = [
                    'chunk_id' => $chunk['chunk_id'],
                    'vector_id' => $chunk['vector_id'],
                    'chunk_index' => (int) $chunk['chunk_index'],
                    'page_start' => (int) $chunk['page_start'],
                    'page_end' => (int) $chunk['page_end'],
                    'text_length' => (int) $chunk['text_length'],
                    'token_count' => (int) $chunk['token_count'],
                    'text' => $chunk['text'],
                ];
            }
            file_put_contents($dir . '/chunks.json', json_encode($chunkRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            if ($vectorsExported && (int) $doc['chunk_count'] > 0 && $doc['embedding_model'] !== '') {
                $collection = MilvusClient::collectionFor((string) $doc['embedding_model']);
                try {
                    $vectors = $milvus->queryAll($collection, sprintf('document_version_id == "%s"', $versionId), array_merge(MilvusClient::OUTPUT_FIELDS, ['vector']));
                    file_put_contents($dir . '/vectors.json', json_encode($vectors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    $vectorTotal += count($vectors);
                } catch (\Throwable) {
                    $vectorsExported = false;
                }
            }
        }
        $manifest['documents'] = count($seenDocuments);
        $manifest['document_versions'] = count($documents);
        $manifest['chunks'] = $chunkTotal;
        $manifest['vectors'] = $vectorTotal;
        $manifest['original_bytes'] = $originalBytes;
        $manifest['base64_bytes'] = $base64Bytes;
        $manifest['vectors_exported'] = $vectorsExported;

        // ---- MySQL dumps (JSON, ID-preserving) ----
        $this->ensureDir($tmp . '/mysql');
        $this->writeJson($tmp . '/mysql/documents.json', $documents);
        $this->writeJson($tmp . '/mysql/jobs.json', $jobs);
        $this->writeJson($tmp . '/mysql/document_blobs.json', Db::fetchAll('SELECT blob_id, document_id, document_version_id, encoding, mime_type, original_filename, file_size, sha256, created_at FROM document_blobs ORDER BY blob_id'));
        $this->writeJson($tmp . '/mysql/document_chunks.json', Db::fetchAll('SELECT id, document_id, chunk_id, vector_id, chunk_index, page_start, page_end, text_length, token_count, text FROM document_chunks ORDER BY id'));

        // ---- Milvus collections ----
        $this->ensureDir($tmp . '/milvus');
        $this->writeJson($tmp . '/milvus/collections.json', $collections);
        $manifest['collections'] = count($collections);

        // ---- Config ----
        $this->ensureDir($tmp . '/config');
        $this->writeJson($tmp . '/config/embedding-models.json', $models);
        $manifest['embedding_models'] = $this->usedEmbeddingModels($models, $documents);

        // ---- Manifest + checksums ----
        $this->writeJson($tmp . '/manifest.json', $manifest);
        $this->writeChecksums($tmp);

        // ---- Build tar.gz ----
        $tar = $root . '/' . $exportId . '.tar';
        $phar = new \PharData($tar);
        $phar->buildFromDirectory($tmp);
        $phar->compress(\Phar::GZ);
        @unlink($tar);
        $this->removeTree($tmp);

        $final = $root . '/' . $exportId . '.tar.gz';
        $size = filesize($final) ?: 0;
        $checksum = hash_file('sha256', $final) ?: '';

        // ---- Post-export integrity verification (required before success) ----
        $verification = $this->verifyArchive($final);
        if (!$verification['ok']) {
            @unlink($final);
            throw new \RuntimeException('Export verification failed: ' . implode('; ', $verification['errors']));
        }

        Db::execute(
            'INSERT INTO exports (export_id, filename, `format`, status, manifest, checksum, file_size, finished_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $exportId,
                $exportId . '.tar.gz',
                'tar.gz',
                'COMPLETED',
                json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $checksum,
                $size,
                gmdate('Y-m-d H:i:s'),
            ]
        );
        Audit::record('export.created', 'export', $exportId, ['size' => $size, 'checksum' => $checksum, 'documents' => $manifest['documents']]);

        $row = $this->getByUuid($exportId);

        return $row ?? ['export_id' => $exportId];
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return Db::fetchAll('SELECT id, export_id, filename, `format`, status, checksum, file_size, created_at, finished_at FROM exports ORDER BY id DESC LIMIT 100');
    }

    /** @return array<string,mixed>|null */
    public function getByUuid(string $exportId): ?array
    {
        return Db::fetchOne('SELECT * FROM exports WHERE export_id = ?', [$exportId]);
    }

    public function download(string $exportId): ?string
    {
        $row = $this->getByUuid($exportId);
        if ($row === null) {
            return null;
        }
        $path = $this->exportRoot() . '/' . $row['filename'];

        return is_file($path) ? $path : null;
    }

    /**
     * Import from an exported archive (tar.gz). IDs are preserved.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function importArchive(string $path, array $options = []): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Archive file not found');
        }
        $strategy = (string) ($options['strategy'] ?? 'skip');
        if (!in_array($strategy, ['skip', 'overwrite'], true)) {
            throw new \InvalidArgumentException('Unknown conflict strategy: ' . $strategy);
        }

        $extract = $this->extractToTemp($path);
        try {
            $manifest = $this->readManifest($extract);
            $this->assertCompatible($manifest);
            $verify = $this->verifyExtracted($extract, $manifest);
            if (!$verify['ok']) {
                throw new \RuntimeException('Archive integrity check failed: ' . implode('; ', $verify['errors']));
            }

            return $this->applyImport($extract, $manifest, $strategy);
        } finally {
            $this->removeTree($extract);
        }
    }

    /** @param array<string,mixed> $manifest */
    public function import(array $manifest): array
    {
        return $this->applyManifestImport($manifest);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $doc @param array<string,mixed>|null $blob @return array<string,mixed> */
    private function docMetadata(array $doc, ?array $blob): array
    {
        $meta = $doc;
        unset($meta['blob_id']);
        $meta['original_filename'] = $blob['original_filename'] ?? $doc['filename'];
        $meta['blob_mime_type'] = $blob['mime_type'] ?? $doc['mime_type'];
        $meta['blob_encoding'] = $blob['encoding'] ?? 'base64';
        $meta['blob_sha256'] = $blob['sha256'] ?? $doc['file_hash'];

        return $meta;
    }

    private function writeJson(string $path, mixed $data): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * Names of embedding models represented by an export: the union of the
     * models actually referenced by exported documents and any model flagged
     * active in the catalog. This keeps the manifest accurate even when the
     * `active` flag has not been maintained in the source database.
     *
     * @param list<array<string,mixed>> $models
     * @param list<array<string,mixed>> $documents
     * @return list<string>
     */
    private function usedEmbeddingModels(array $models, array $documents): array
    {
        $names = [];
        foreach ($models as $m) {
            if ((int) ($m['active'] ?? 0) === 1) {
                $names[(string) $m['name']] = true;
            }
        }
        foreach ($documents as $d) {
            $name = trim((string) ($d['embedding_model'] ?? ''));
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        $result = array_keys($names);
        sort($result);

        return $result;
    }

    private function writeChecksums(string $tmp): void
    {
        $lines = [];
        $files = $this->recursiveFiles($tmp);
        sort($files);
        foreach ($files as $file) {
            $rel = ltrim(substr($file, strlen($tmp)), '/');
            $lines[] = hash_file('sha256', $file) . '  ' . $rel;
        }
        $this->ensureDir($tmp . '/checksums');
        file_put_contents($tmp . '/checksums/SHA256SUMS', implode("\n", $lines) . "\n");
    }

    /** @return list<string> */
    private function recursiveFiles(string $dir): array
    {
        $out = [];
        $items = scandir($dir);
        if ($items === false) {
            return $out;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $out = array_merge($out, $this->recursiveFiles($path));
            } else {
                $out[] = $path;
            }
        }

        return $out;
    }

    /** @return array{ok:bool,errors:list<string>} */
    private function verifyArchive(string $archive): array
    {
        $extract = $this->extractToTemp($archive);
        try {
            $manifest = $this->readManifest($extract);

            return $this->verifyExtracted($extract, $manifest);
        } finally {
            $this->removeTree($extract);
        }
    }

    /** @return array{ok:bool,errors:list<string>} */
    private function verifyExtracted(string $extract, array $manifest): array
    {
        $errors = [];

        $sumsFile = $extract . '/checksums/SHA256SUMS';
        if (!is_file($sumsFile)) {
            $errors[] = 'checksums/SHA256SUMS missing';
        } else {
            foreach (file($sumsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (preg_match('/^([0-9a-f]{64})  (.+)$/', $line, $m) !== 1) {
                    $errors[] = 'Malformed SHA256SUMS line: ' . $line;
                    continue;
                }
                $target = $extract . '/' . $m[2];
                if (!is_file($target)) {
                    $errors[] = 'Missing file: ' . $m[2];
                    continue;
                }
                if (hash_file('sha256', $target) !== $m[1]) {
                    $errors[] = 'Checksum mismatch: ' . $m[2];
                }
            }
        }

        $docsDir = $extract . '/documents';
        if (is_dir($docsDir)) {
            foreach (scandir($docsDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $dir = $docsDir . '/' . $entry;
                if (!is_dir($dir)) {
                    continue;
                }
                $expected = trim((string) @file_get_contents($dir . '/checksum.sha256'));
                $hash = $this->sha256OfBase64File($dir . '/original.b64');
                if ($hash === null || $hash !== $expected) {
                    $errors[] = 'Original hash mismatch for version ' . $entry;
                }
            }
        }

        $versions = 0;
        $chunks = 0;
        $documentsSeen = [];
        if (is_dir($docsDir)) {
            foreach (scandir($docsDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || !is_dir($docsDir . '/' . $entry)) {
                    continue;
                }
                $versions++;
                $meta = json_decode((string) @file_get_contents($docsDir . '/' . $entry . '/metadata.json'), true);
                if (is_array($meta)) {
                    $documentsSeen[(string) ($meta['document_id'] ?? $entry)] = true;
                }
                $chunkRows = json_decode((string) @file_get_contents($docsDir . '/' . $entry . '/chunks.json'), true);
                if (is_array($chunkRows)) {
                    $chunks += count($chunkRows);
                }
            }
        }
        if ((int) ($manifest['document_versions'] ?? 0) !== $versions) {
            $errors[] = 'Manifest version count mismatch';
        }
        if ((int) ($manifest['chunks'] ?? 0) !== $chunks) {
            $errors[] = 'Manifest chunk count mismatch';
        }
        if ((int) ($manifest['documents'] ?? 0) !== count($documentsSeen)) {
            $errors[] = 'Manifest document count mismatch';
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    private function sha256OfBase64File(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $ctx = hash_init('sha256');
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        while (!feof($fh)) {
            $part = fread($fh, 8192);
            if ($part === false) {
                fclose($fh);

                return null;
            }
            if ($part === '') {
                continue;
            }
            $decoded = base64_decode($part, true);
            if ($decoded === false) {
                fclose($fh);

                return null;
            }
            hash_update($ctx, $decoded);
        }
        fclose($fh);

        return hash_final($ctx);
    }

    /** @return array<string,mixed> */
    private function readManifest(string $extract): array
    {
        $raw = (string) @file_get_contents($extract . '/manifest.json');
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            throw new \InvalidArgumentException('Invalid or missing manifest.json');
        }

        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    private function assertCompatible(array $manifest): void
    {
        $version = (string) ($manifest['export_format_version'] ?? '');
        if ($version !== self::FORMAT_VERSION) {
            throw new \InvalidArgumentException('Incompatible export format version: ' . ($version ?: 'unknown'));
        }
    }

    private function extractToTemp(string $archive): string
    {
        $tmp = $this->exportRoot() . '/.import_' . Uuid::v4();
        $this->ensureDir($tmp);

        // PharData caches opened archives by path. Re-opening a path that was
        // just produced by buildFromDirectory()+compress() in the same process
        // can yield empty file contents. Extract from a uniquely-named copy so
        // the archive is always read fresh from disk.
        $copy = $this->exportRoot() . '/.archive_' . Uuid::v4() . '.tar.gz';
        if (!copy($archive, $copy)) {
            throw new \RuntimeException('Failed to stage archive for extraction');
        }
        try {
            $phar = new \PharData($copy);
            $phar->extractTo($tmp, null, true);
        } finally {
            @unlink($copy);
        }

        return $tmp;
    }

    /**
     * Apply an extracted, verified archive to MySQL + Milvus.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private function applyImport(string $extract, array $manifest, string $strategy): array
    {
        $result = ['imported' => 0, 'reused' => 0, 'skipped' => 0, 'overwritten' => 0, 'conflicts' => 0];
        $docsDir = $extract . '/documents';
        if (!is_dir($docsDir)) {
            throw new \InvalidArgumentException('Archive has no documents directory');
        }

        $milvus = new MilvusClient();
        $touchedCollections = [];
        foreach (scandir($docsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($docsDir . '/' . $entry)) {
                continue;
            }
            $dir = $docsDir . '/' . $entry;
            $metadata = json_decode((string) @file_get_contents($dir . '/metadata.json'), true);
            if (!is_array($metadata)) {
                $result['skipped']++;
                continue;
            }
            $documentId = (string) ($metadata['document_id'] ?? $entry);
            $versionId = (string) ($metadata['document_version_id'] ?? $entry);
            $hash = trim((string) @file_get_contents($dir . '/checksum.sha256'));
            $base64 = (string) @file_get_contents($dir . '/original.b64');
            $chunks = json_decode((string) @file_get_contents($dir . '/chunks.json'), true);
            $chunks = is_array($chunks) ? $chunks : [];
            $vectors = json_decode((string) @file_get_contents($dir . '/vectors.json'), true);
            $vectors = is_array($vectors) ? $vectors : [];

            $existing = Db::fetchOne('SELECT id, file_hash FROM documents WHERE document_version_id = ? LIMIT 1', [$versionId]);
            if ($existing !== null) {
                if ($existing['file_hash'] === $hash) {
                    $result['reused']++;
                    continue;
                }
                if ($strategy !== 'overwrite') {
                    $result['conflicts']++;
                    continue;
                }
                Db::transaction(function () use ($versionId): void {
                    Db::execute('DELETE FROM document_blobs WHERE document_version_id = ?', [$versionId]);
                    Db::execute('DELETE FROM documents WHERE document_version_id = ?', [$versionId]);
                });
                $result['overwritten']++;
            }

            $this->importVersion($metadata, $documentId, $versionId, $hash, $base64, $chunks, $vectors, $milvus);
            $result['imported']++;

            if ($vectors !== [] && ($metadata['embedding_model'] ?? '') !== '') {
                $touchedCollections[MilvusClient::collectionFor((string) $metadata['embedding_model'])] = true;
            }
        }

        // Flush each collection once so imported vectors move into sealed
        // segments and become visible to stats (Milvus rowCount only reflects
        // flushed segments). Best-effort: a failed flush must not fail an
        // otherwise successful import, and search already sees growing segments.
        foreach (array_keys($touchedCollections) as $collection) {
            try {
                $milvus->flush($collection);
            } catch (\Throwable $e) {
                Logger::channel('import')->warning('collection flush failed', [
                    'collection' => $collection,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $manifest */
    private function applyManifestImport(array $manifest): array
    {
        if (empty($manifest['documents']) || !is_array($manifest['documents'])) {
            throw new \InvalidArgumentException('Manifest has no documents');
        }
        $imported = 0;
        $skipped = 0;
        foreach ($manifest['documents'] as $doc) {
            $documentId = (string) ($doc['document_id'] ?? '');
            $versionId = (string) ($doc['document_version_id'] ?? $documentId);
            $hash = (string) ($doc['file_hash'] ?? '');
            if ($documentId === '' || $hash === '') {
                $skipped++;
                continue;
            }
            if (Db::fetchOne('SELECT id FROM documents WHERE document_version_id = ? LIMIT 1', [$versionId]) !== null) {
                $skipped++;
                continue;
            }
            $chunks = is_array($doc['chunks'] ?? null) ? $doc['chunks'] : [];
            $this->importVersion($doc, $documentId, $versionId, $hash, (string) ($doc['base64'] ?? ''), $chunks, [], new MilvusClient());
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @param array<string,mixed> $meta
     * @param list<array<string,mixed>> $chunks
     * @param list<array<string,mixed>> $vectors
     */
    private function importVersion(array $meta, string $documentId, string $versionId, string $hash, string $base64, array $chunks, array $vectors, MilvusClient $milvus): void
    {
        Db::transaction(function () use ($meta, $documentId, $versionId, $hash, $base64, $chunks): void {
            Db::execute(
                'INSERT INTO document_blobs (document_id, document_version_id, encoding, mime_type, original_filename, file_size, sha256, base64_data)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $documentId,
                    $versionId,
                    (string) ($meta['blob_encoding'] ?? 'base64'),
                    (string) ($meta['blob_mime_type'] ?? $meta['mime_type'] ?? ''),
                    (string) ($meta['original_filename'] ?? $meta['filename'] ?? ''),
                    (int) ($meta['file_size'] ?? 0),
                    $hash,
                    $base64,
                ]
            );
            $blobId = Db::insertId();

            $metadata = $meta['metadata'] ?? null;
            $metadataJson = $metadata === null
                ? null
                : (is_array($metadata) ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $metadata);

            Db::execute(
                'INSERT INTO documents (document_id, document_version_id, version, is_current, job_id, source_path, relative_path, filename, extension, mime_type, file_size, file_hash, blob_id, created_at, modified_at, indexed_at, page_count, character_count, word_count, token_count_estimate, chunk_count, embedding_model, embedding_dimension, processing_status, processing_duration, error_message, metadata)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $documentId,
                    $versionId,
                    (int) ($meta['version'] ?? 1),
                    1,
                    null,
                    (string) ($meta['source_path'] ?? ''),
                    (string) ($meta['relative_path'] ?? ''),
                    (string) ($meta['filename'] ?? ''),
                    (string) ($meta['extension'] ?? ''),
                    (string) ($meta['mime_type'] ?? ''),
                    (int) ($meta['file_size'] ?? 0),
                    $hash,
                    $blobId,
                    $this->nullableDate($meta['created_at']),
                    $this->nullableDate($meta['modified_at']),
                    $this->nullableDate($meta['indexed_at']),
                    (int) ($meta['page_count'] ?? 0),
                    (int) ($meta['character_count'] ?? 0),
                    (int) ($meta['word_count'] ?? 0),
                    (int) ($meta['token_count_estimate'] ?? 0),
                    (int) ($meta['chunk_count'] ?? count($chunks)),
                    (string) ($meta['embedding_model'] ?? ''),
                    (int) ($meta['embedding_dimension'] ?? 0),
                    (string) ($meta['processing_status'] ?? 'IMPORTED'),
                    (int) ($meta['processing_duration'] ?? 0),
                    (string) ($meta['error_message'] ?? ''),
                    $metadataJson,
                ]
            );
            $rowId = Db::insertId();

            foreach ($chunks as $chunk) {
                $chunkId = (string) ($chunk['chunk_id'] ?? Uuid::v4());
                $vectorId = (string) ($chunk['vector_id'] ?? '');
                $text = (string) ($chunk['text'] ?? '');
                Db::execute(
                    'INSERT INTO document_chunks (document_id, chunk_id, vector_id, chunk_index, page_start, page_end, text_length, token_count, text)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $rowId,
                        $chunkId,
                        $vectorId !== '' ? $vectorId : null,
                        (int) ($chunk['chunk_index'] ?? 0),
                        (int) ($chunk['page_start'] ?? 0),
                        (int) ($chunk['page_end'] ?? 0),
                        mb_strlen($text),
                        (int) ($chunk['token_count'] ?? 0),
                        $text,
                    ]
                );
            }
        });

        if ($vectors !== [] && ($meta['embedding_model'] ?? '') !== '') {
            $collection = MilvusClient::collectionFor((string) $meta['embedding_model']);
            try {
                if (!$milvus->hasCollection($collection)) {
                    $milvus->createCollection($collection, (int) ($meta['embedding_dimension'] ?? 0), 'COSINE');
                }
                $milvus->insert($collection, $vectors);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Imported MySQL data but Milvus insert failed: ' . $e->getMessage());
            }
        }
    }

    private function nullableDate(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return $value;
    }

    private function removeTree(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
