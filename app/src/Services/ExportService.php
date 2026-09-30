<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Uuid;
use App\Security\PathGuard;

/**
 * Long-term offline export/import. Produces a gzip-compressed tar archive that
 * bundles a JSON manifest plus every stored chunk (text) so a full document set
 * can be reconstructed and re-embedded without the original files or network.
 */
final class ExportService
{
    public function exportRoot(): string
    {
        return Config::string('EXPORT_ROOT', '/srv/data/exports');
    }

    /**
     * Create an export of all completed documents and their chunks.
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
        @mkdir($tmp, 0755, true);
        $manifest = [
            'export_id' => $exportId,
            'format' => 'tar.gz',
            'app_version' => Config::string('APP_VERSION', '0.0.0'),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'schema_version' => 1,
        ];
        $documents = Db::fetchAll('SELECT * FROM documents ORDER BY id');
        $manifest['documents'] = [];
        foreach ($documents as $doc) {
            $chunks = Db::fetchAll('SELECT * FROM document_chunks WHERE document_id = ? ORDER BY chunk_index', [$doc['id']]);
            $docData = [
                'document_id' => $doc['document_id'],
                'relative_path' => $doc['relative_path'],
                'filename' => $doc['filename'],
                'extension' => $doc['extension'],
                'mime_type' => $doc['mime_type'],
                'file_size' => (int) $doc['file_size'],
                'file_hash' => $doc['file_hash'],
                'embedding_model' => $doc['embedding_model'],
                'embedding_dimension' => (int) $doc['embedding_dimension'],
                'chunks' => [],
            ];
            foreach ($chunks as $chunk) {
                $docData['chunks'][] = [
                    'chunk_index' => (int) $chunk['chunk_index'],
                    'page_start' => (int) $chunk['page_start'],
                    'page_end' => (int) $chunk['page_end'],
                    'text' => $chunk['text'],
                ];
            }
            $manifest['documents'][] = $docData;
        }
        file_put_contents($tmp . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $tar = $root . '/' . $exportId . '.tar';
        $phar = new \PharData($tar);
        $phar->buildFromDirectory($tmp);
        $phar->compress(\Phar::GZ);

        @unlink($tar);
        $this->removeTree($tmp);

        $final = $root . '/' . $exportId . '.tar.gz';
        $size = filesize($final) ?: 0;
        $checksum = hash_file('sha256', $final) ?: '';

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
        Audit::record('export.created', 'export', $exportId, ['size' => $size, 'checksum' => $checksum]);

        return $this->getByUuid($exportId) ?? ['export_id' => $exportId];
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

    /** @param array<string,mixed> $manifest */
    public function import(array $manifest): array
    {
        if (empty($manifest['documents']) || !is_array($manifest['documents'])) {
            throw new \InvalidArgumentException('Manifest has no documents');
        }
        $imported = 0;
        $skipped = 0;
        foreach ($manifest['documents'] as $doc) {
            if (empty($doc['document_id']) || empty($doc['file_hash'])) {
                $skipped++;
                continue;
            }
            $exists = Db::fetchOne('SELECT id FROM documents WHERE file_hash = ? LIMIT 1', [$doc['file_hash']]);
            if ($exists) {
                $skipped++;
                continue;
            }
            Db::transaction(function () use ($doc, &$imported): void {
                $documentId = (string) $doc['document_id'];
                Db::execute(
                    'INSERT INTO documents (document_id, source_path, relative_path, filename, extension, mime_type, file_size, file_hash, character_count, chunk_count, embedding_model, embedding_dimension, processing_status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, "IMPORTED")',
                    [
                        $documentId,
                        (string) ($doc['relative_path'] ?? ''),
                        (string) ($doc['relative_path'] ?? ''),
                        (string) ($doc['filename'] ?? ''),
                        (string) ($doc['extension'] ?? ''),
                        (string) ($doc['mime_type'] ?? ''),
                        (int) ($doc['file_size'] ?? 0),
                        (string) $doc['file_hash'],
                        (string) ($doc['embedding_model'] ?? ''),
                        (int) ($doc['embedding_dimension'] ?? 0),
                    ]
                );
                $id = Db::insertId();
                foreach (($doc['chunks'] ?? []) as $chunk) {
                    Db::execute(
                        'INSERT INTO document_chunks (document_id, chunk_id, chunk_index, page_start, page_end, text_length, token_count, text)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $id,
                            Uuid::v4(),
                            (int) ($chunk['chunk_index'] ?? 0),
                            (int) ($chunk['page_start'] ?? 0),
                            (int) ($chunk['page_end'] ?? 0),
                            mb_strlen((string) ($chunk['text'] ?? '')),
                            Chunker::estimateTokens((string) ($chunk['text'] ?? '')),
                            (string) ($chunk['text'] ?? ''),
                        ]
                    );
                }
                Db::execute('UPDATE documents SET chunk_count = (SELECT COUNT(*) FROM document_chunks WHERE document_id = ?) WHERE id = ?', [$id, $id]);
                $imported++;
            });
        }

        return ['imported' => $imported, 'skipped' => $skipped];
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
