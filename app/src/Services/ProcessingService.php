<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Logger;
use App\Core\Uuid;
use App\Security\PathGuard;

/**
 * The document ingestion pipeline used by the worker. Each step is idempotent
 * and crash-safe: discovery is keyed by SHA-256, conversion/chunking/embedding
 * status is persisted per document, and Milvus inserts are batched.
 */
final class ProcessingService
{
    /** Supported document extensions => converter hints. */
    public const SUPPORTED_EXTENSIONS = [
        'pdf', 'docx', 'doc', 'odt', 'rtf', 'pptx', 'ppt', 'odp',
        'html', 'htm', 'epub', 'xlsx', 'xls', 'ods', 'csv', 'txt', 'md', 'markdown',
    ];

    private ConverterClient $converter;
    private EmbeddingClient $embedding;
    private MilvusClient $milvus;

    public function __construct()
    {
        $this->converter = new ConverterClient();
        $this->embedding = new EmbeddingClient();
        $this->milvus = new MilvusClient();
    }

    /** @return array<string,mixed> */
    public function chunkerConfig(): array
    {
        return [
            'max_tokens' => Config::int('CHUNK_SIZE_TOKENS', 512),
            'overlap_tokens' => Config::int('CHUNK_OVERLAP_TOKENS', 64),
            'hard_max_tokens' => Config::int('CHUNK_MAX_TOKENS', 1024),
            'batch_size' => Config::int('EMBED_BATCH_SIZE', 32),
        ];
    }

    /**
     * Scan the job's source directory and register discovered files. Versions
     * are grouped by stable source path (a grouping hint only — identity is the
     * generated `document_id`/`document_version_id`), and identical content is
     * deduplicated by SHA-256. Returns the number of newly created versions.
     *
     * @param array<string,mixed> $job
     */
    public function discover(array $job): int
    {
        $root = Config::string('INPUT_ROOT', '/srv/data/input');
        $source = $job['source_directory'];
        $absolute = PathGuard::resolve($root, $source);
        if (!is_dir($absolute)) {
            throw new \InvalidArgumentException('Source directory not found: ' . $source);
        }
        $recursive = (bool) $job['recursive'];
        $files = $this->scanFiles($absolute, $recursive);
        $newCount = 0;
        foreach ($files as $file) {
            $relative = ltrim(substr($file, strlen($absolute)), '/');
            $hash = hash_file('sha256', $file);
            if ($hash === false) {
                continue;
            }

            // 1. Same source path, same content -> unchanged.
            $current = Db::fetchOne(
                'SELECT id, document_id, file_hash FROM documents WHERE source_path = ? AND is_current = 1 ORDER BY version DESC LIMIT 1',
                [$file]
            );
            if ($current !== null && $current['file_hash'] === $hash) {
                continue;
            }

            // 2. Same source path, different content -> new version.
            if ($current !== null) {
                $this->insertVersion($job, $file, $relative, $hash, (string) $current['document_id']);
                $newCount++;
                continue;
            }

            // 3. No document at this path yet -> global content dedupe.
            $dup = Db::fetchOne('SELECT id FROM documents WHERE file_hash = ? LIMIT 1', [$hash]);
            if ($dup !== null) {
                continue;
            }

            $this->insertDiscovered($job, $file, $relative, $hash);
            $newCount++;
        }

        return $newCount;
    }

    /** @return list<string> */
    private function scanFiles(string $dir, bool $recursive): array
    {
        $result = [];
        $items = scandir($dir);
        if ($items === false) {
            return $result;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . '/' . $item;
            if (is_dir($full)) {
                if ($recursive) {
                    $result = array_merge($result, $this->scanFiles($full, true));
                }
                continue;
            }
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, self::SUPPORTED_EXTENSIONS, true)) {
                $result[] = $full;
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $job */
    private function insertDiscovered(array $job, string $absolute, string $relative, string $hash): void
    {
        $documentId = Uuid::v4();
        $this->insertDocumentVersion($job, $absolute, $relative, $hash, $documentId, $documentId, 1);
    }

    /** @param array<string,mixed> $job */
    private function insertVersion(array $job, string $absolute, string $relative, string $hash, string $documentId): void
    {
        $current = Db::fetchOne('SELECT version FROM documents WHERE document_id = ? ORDER BY version DESC LIMIT 1', [$documentId]);
        $version = ((int) ($current['version'] ?? 0)) + 1;

        // Retire the current version so it never points at the new vectors.
        Db::execute('UPDATE documents SET is_current = 0 WHERE document_id = ? AND is_current = 1', [$documentId]);

        $this->insertDocumentVersion($job, $absolute, $relative, $hash, $documentId, Uuid::v4(), $version);
    }

    /**
     * Insert one document version plus its original-file blob atomically.
     *
     * @param array<string,mixed> $job
     */
    private function insertDocumentVersion(array $job, string $absolute, string $relative, string $hash, string $documentId, string $documentVersionId, int $version): void
    {
        $stat = stat($absolute);
        $isStat = is_array($stat);
        $size = $isStat ? (int) ($stat['size'] ?? 0) : 0;
        $ctime = $isStat && isset($stat['ctime']) ? gmdate('Y-m-d H:i:s', $stat['ctime']) : null;
        $mtime = $isStat && isset($stat['mtime']) ? gmdate('Y-m-d H:i:s', $stat['mtime']) : null;
        $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));

        Db::transaction(function () use ($job, $absolute, $relative, $hash, $documentId, $documentVersionId, $version, $size, $ctime, $mtime, $ext): void {
            Db::execute(
                'INSERT INTO documents (document_id, document_version_id, version, is_current, job_id, source_path, relative_path, filename, extension, mime_type, file_size, file_hash, created_at, modified_at, processing_status, embedding_model, embedding_dimension)
                 VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "DISCOVERED", ?, ?)',
                [
                    $documentId,
                    $documentVersionId,
                    $version,
                    $job['id'],
                    $absolute,
                    $relative,
                    basename($absolute),
                    $ext,
                    $this->guessMime($ext),
                    $size,
                    $hash,
                    $ctime,
                    $mtime,
                    $job['embedding_model'],
                    (int) $job['embedding_dimension'],
                ]
            );
            $id = Db::insertId();
            $blobId = $this->storeBlob($documentId, $documentVersionId, $absolute, $hash, $size);
            Db::execute('UPDATE documents SET blob_id = ? WHERE id = ?', [$blobId, $id]);
        });
    }

    /** Store the original file byte-for-byte as Base64; returns the blob id. */
    private function storeBlob(string $documentId, string $documentVersionId, string $absolute, string $hash, int $size): int
    {
        $bytes = file_get_contents($absolute);
        if ($bytes === false) {
            throw new \RuntimeException('Cannot read source file for blob storage: ' . $absolute);
        }
        $base64 = base64_encode($bytes);
        $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
        Db::execute(
            'INSERT INTO document_blobs (document_id, document_version_id, encoding, mime_type, original_filename, file_size, sha256, base64_data)
             VALUES (?, ?, "base64", ?, ?, ?, ?, ?)',
            [
                $documentId,
                $documentVersionId,
                $this->guessMime($ext),
                basename($absolute),
                $size > 0 ? $size : strlen($bytes),
                $hash,
                $base64,
            ]
        );

        return Db::insertId();
    }

    private function guessMime(string $ext): string
    {
        return match ($ext) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc' => 'application/msword',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'ppt' => 'application/vnd.ms-powerpoint',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odp' => 'application/vnd.oasis.opendocument.presentation',
            'rtf' => 'application/rtf',
            'epub' => 'application/epub+zip',
            'txt' => 'text/plain',
            'md', 'markdown' => 'text/markdown',
            'csv' => 'text/csv',
            'html', 'htm' => 'text/html',
            default => 'application/octet-stream',
        };
    }

    /**
     * Process a single document: convert -> chunk -> embed -> persist -> Milvus.
     *
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    public function processDocument(array $doc): array
    {
        $id = (int) $doc['id'];
        $start = microtime(true);
        Db::execute('UPDATE documents SET processing_status = "PROCESSING" WHERE id = ?', [$id]);

        try {
            $converted = $this->converter->convert((string) $doc['source_path']);
            $text = (string) ($converted['text'] ?? '');
            $pageCount = (int) ($converted['page_count'] ?? 0);

            $chunks = $this->chunkByPage($text, $pageCount, $doc['extension'] === 'pdf');

            $collection = MilvusClient::collectionFor((string) $doc['embedding_model']);
            $dimension = (int) $doc['embedding_dimension'];
            $this->ensureCollection($collection, $dimension, 'cosine');

            $jobUuid = Db::fetchValue('SELECT job_id FROM jobs WHERE id = ?', [$doc['job_id']], '');

            $batchSize = (int) $this->chunkerConfig()['batch_size'];
            $chunkRows = [];
            $storedChunks = 0;
            foreach (array_chunk($chunks, $batchSize) as $batch) {
                $texts = array_map(static fn (array $c): string => $c['text'], $batch);
                $embeddings = $this->embedding->embedBatch($texts);
                foreach ($batch as $i => $chunk) {
                    $vector = $this->findEmbedding($embeddings, $i);
                    if ($vector === null) {
                        throw new \RuntimeException('Embedding service did not return a vector for index ' . $i);
                    }
                    $chunkId = Uuid::v4();
                    $vectorId = Uuid::v4();
                    $chunkRows[] = [
                        'id' => $vectorId,
                        'document_id' => (string) $doc['document_id'],
                        'document_version_id' => (string) $doc['document_version_id'],
                        'chunk_id' => $chunkId,
                        'job_id' => (string) $jobUuid,
                        'source_path' => (string) $doc['source_path'],
                        'filename' => (string) $doc['filename'],
                        'document_hash' => (string) $doc['file_hash'],
                        'embedding_model' => (string) $doc['embedding_model'],
                        'embedding_dimension' => $dimension,
                        'chunk_index' => (int) $chunk['chunk_index'],
                        'page_start' => (int) $chunk['page_start'],
                        'page_end' => (int) $chunk['page_end'],
                        'vector' => $vector,
                    ];
                    $this->persistChunk($id, $chunkId, $vectorId, $chunk);
                    $storedChunks++;
                }
                if ($chunkRows !== []) {
                    $this->milvus->insert($collection, $chunkRows);
                    $chunkRows = [];
                }
            }

            // Note: the collection is flushed once per job (see worker finalizeJob),
            // not per document. Milvus rate-limits collection flushes (0.1 qps by
            // default), so flushing here would fail on the second document.

            $characterCount = mb_strlen($text);
            $wordCount = $this->wordCount($text);
            Db::execute(
                'UPDATE documents SET processing_status = "COMPLETED", indexed_at = ?, page_count = ?, character_count = ?, word_count = ?, token_count_estimate = ?, chunk_count = ?, processing_duration = ?, error_message = NULL WHERE id = ?',
                [
                    gmdate('Y-m-d H:i:s'),
                    $pageCount,
                    $characterCount,
                    $wordCount,
                    array_sum(array_map(static fn (array $c): int => $c['token_count'], $chunks)),
                    $storedChunks,
                    (int) round(microtime(true) - $start),
                    $id,
                ]
            );

            return ['status' => 'COMPLETED', 'chunks' => $storedChunks, 'duration' => (int) round(microtime(true) - $start)];
        } catch (\Throwable $e) {
            Db::execute(
                'UPDATE documents SET processing_status = "FAILED", error_message = ?, processing_duration = ? WHERE id = ?',
                [substr($e->getMessage(), 0, 2000), (int) round(microtime(true) - $start), $id]
            );
            Db::execute(
                'INSERT INTO processing_errors (job_id, document_id, step, error_message) VALUES (?, ?, ?, ?)',
                [$doc['job_id'], $id, 'process', substr($e->getMessage(), 0, 2000)]
            );
            Logger::channel('worker')->error('document processing failed', [
                'document_id' => $doc['document_id'],
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'FAILED', 'error' => $e->getMessage()];
        }
    }

    /**
     * Chunk text, honouring PDF page boundaries when form-feed markers exist.
     *
     * @return list<array{text:string,token_count:int,chunk_index:int,page_start:int,page_end:int}>
     */
    private function chunkByPage(string $text, int $pageCount, bool $isPdf): array
    {
        $config = $this->chunkerConfig();
        $chunker = new Chunker($config['max_tokens'], $config['overlap_tokens'], $config['hard_max_tokens']);

        $chunks = [];
        $index = 0;
        if ($isPdf && str_contains($text, "\f")) {
            $pages = explode("\f", $text);
            foreach ($pages as $pageNum => $pageText) {
                foreach ($chunker->chunk($pageText) as $chunk) {
                    $chunks[] = [
                        'text' => $chunk['text'],
                        'token_count' => $chunk['token_count'],
                        'chunk_index' => $index++,
                        'page_start' => $pageNum + 1,
                        'page_end' => $pageNum + 1,
                    ];
                }
            }
        } else {
            $pageNo = max(1, $pageCount);
            foreach ($chunker->chunk($text) as $chunk) {
                $chunks[] = [
                    'text' => $chunk['text'],
                    'token_count' => $chunk['token_count'],
                    'chunk_index' => $index++,
                    'page_start' => $pageNo,
                    'page_end' => $pageNo,
                ];
            }
        }

        return $chunks;
    }

    /** @param array{text:string,token_count:int,chunk_index:int,page_start:int,page_end:int} $chunk */
    private function persistChunk(int $documentId, string $chunkId, string $vectorId, array $chunk): void
    {
        Db::execute(
            'INSERT INTO document_chunks (document_id, chunk_id, vector_id, chunk_index, page_start, page_end, text_length, token_count, text)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $documentId,
                $chunkId,
                $vectorId,
                $chunk['chunk_index'],
                $chunk['page_start'],
                $chunk['page_end'],
                mb_strlen($chunk['text']),
                $chunk['token_count'],
                $chunk['text'],
            ]
        );
    }

    /** @param list<list<float>> $embeddings @return list<float>|null */
    private function findEmbedding(array $embeddings, int $index): ?array
    {
        $vector = $embeddings[$index] ?? null;

        return is_array($vector) ? $vector : null;
    }

    public function ensureCollection(string $collection, int $dimension, string $metric): void
    {
        if ($this->milvus->hasCollection($collection)) {
            return;
        }
        $this->milvus->createCollection($collection, $dimension, $metric);
        Logger::channel('worker')->info('created Milvus collection', [
            'collection' => $collection,
            'dimension' => $dimension,
        ]);
    }

    private function wordCount(string $text): int
    {
        $count = preg_match_all('/\S+/u', $text);

        return $count === false ? 0 : $count;
    }
}
