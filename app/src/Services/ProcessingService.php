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
     * Scan the job's source directory and register discovered files (dedupe by
     * SHA-256). Returns the number of newly discovered documents.
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
        $seen = [];
        foreach ($files as $file) {
            $relative = ltrim(substr($file, strlen($absolute)), '/');
            $hash = hash_file('sha256', $file);
            if ($hash === false) {
                continue;
            }
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $existing = Db::fetchOne('SELECT id, processing_status FROM documents WHERE file_hash = ? LIMIT 1', [$hash]);
            if ($existing !== null) {
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
        $stat = stat($absolute);
        $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
        Db::execute(
            'INSERT INTO documents (document_id, job_id, source_path, relative_path, filename, extension, mime_type, file_size, file_hash, created_at, modified_at, processing_status, embedding_model, embedding_dimension)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "DISCOVERED", ?, ?)',
            [
                Uuid::v4(),
                $job['id'],
                $absolute,
                $relative,
                basename($absolute),
                $ext,
                $this->guessMime($ext),
                $stat['size'] ?? 0,
                $hash,
                isset($stat['ctime']) ? gmdate('Y-m-d H:i:s', $stat['ctime']) : null,
                isset($stat['mtime']) ? gmdate('Y-m-d H:i:s', $stat['mtime']) : null,
                $job['embedding_model'],
                (int) $job['embedding_dimension'],
            ]
        );
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
                    $chunkRows[] = [
                        'document_id' => (string) $doc['document_id'],
                        'chunk_index' => (int) $chunk['chunk_index'],
                        'vector' => $vector,
                    ];
                    $this->persistChunk($id, $chunkId, $chunk);
                    $storedChunks++;
                }
                if ($chunkRows !== []) {
                    $this->milvus->insert($collection, $chunkRows);
                    $chunkRows = [];
                }
            }

            if ($storedChunks > 0) {
                // Move inserted vectors into sealed segments so rowCount/stats
                // reflect the document immediately.
                $this->milvus->flush($collection);
            }

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
    private function persistChunk(int $documentId, string $chunkId, array $chunk): void
    {
        Db::execute(
            'INSERT INTO document_chunks (document_id, chunk_id, chunk_index, page_start, page_end, text_length, token_count, text)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $documentId,
                $chunkId,
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
