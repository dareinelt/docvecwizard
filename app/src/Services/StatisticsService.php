<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

final class StatisticsService
{
    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        $docCounts = (new DocumentService())->counts();
        $jobs = Db::fetchOne(
            'SELECT COUNT(*) AS total,
                    SUM(status = "RUNNING") AS running,
                    SUM(status = "COMPLETED") AS completed,
                    SUM(status = "FAILED") AS failed
             FROM jobs'
        );
        $totals = Db::fetchOne(
            'SELECT SUM(chunk_count) AS chunks, SUM(token_count_estimate) AS tokens, SUM(file_size) AS bytes FROM documents'
        );
        $models = Db::fetchOne('SELECT COUNT(*) AS total, SUM(active = 1) AS active FROM embedding_models');

        return [
            'documents' => $docCounts,
            'jobs' => [
                'total' => (int) ($jobs['total'] ?? 0),
                'running' => (int) ($jobs['running'] ?? 0),
                'completed' => (int) ($jobs['completed'] ?? 0),
                'failed' => (int) ($jobs['failed'] ?? 0),
            ],
            'totals' => [
                'chunks' => (int) ($totals['chunks'] ?? 0),
                'tokens' => (int) ($totals['tokens'] ?? 0),
                'bytes' => (int) ($totals['bytes'] ?? 0),
            ],
            'models' => [
                'total' => (int) ($models['total'] ?? 0),
                'active' => (int) ($models['active'] ?? 0),
            ],
            'storage' => $this->storage(),
        ];
    }

    /**
     * Measured storage figures (only values actually observable in MySQL).
     * Milvus byte size is reported by the integrity/storage endpoint when the
     * Milvus server is reachable; here it is left null rather than guessed.
     *
     * @return array<string,mixed>
     */
    public function storage(): array
    {
        $blobs = Db::fetchOne(
            'SELECT COALESCE(SUM(file_size),0) AS original_bytes,
                    COALESCE(SUM(LENGTH(base64_data)),0) AS base64_bytes
             FROM document_blobs'
        );
        $db = Db::fetchOne(
            "SELECT COALESCE(SUM(data_length + index_length),0) AS bytes
             FROM information_schema.TABLES
             WHERE table_schema = DATABASE()
               AND table_name IN ('documents','document_blobs','document_chunks','jobs','exports','processing_errors')"
        );
        $vectors = Db::fetchOne(
            'SELECT COUNT(*) AS total,
                    SUM(vector_id IS NOT NULL AND vector_id != "") AS linked
             FROM document_chunks'
        );
        $overhead = ((int) ($blobs['original_bytes'] ?? 0)) > 0
            ? (int) round((((int) ($blobs['base64_bytes'] ?? 0)) / (int) ($blobs['original_bytes'] ?? 1)) * 100)
            : 0;

        return [
            'original_bytes' => (int) ($blobs['original_bytes'] ?? 0),
            'base64_bytes' => (int) ($blobs['base64_bytes'] ?? 0),
            'base64_overhead_percent' => $overhead,
            'mysql_bytes' => (int) ($db['bytes'] ?? 0),
            'milvus_bytes' => null,
            'total_bytes' => (int) ($blobs['original_bytes'] ?? 0) + (int) ($blobs['base64_bytes'] ?? 0) + (int) ($db['bytes'] ?? 0),
            'vectors' => [
                'total' => (int) ($vectors['total'] ?? 0),
                'linked' => (int) ($vectors['linked'] ?? 0),
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function jobsSummary(): array
    {
        return Db::fetchAll(
            'SELECT status, COUNT(*) AS count FROM jobs GROUP BY status ORDER BY status'
        );
    }

    /** @return list<array<string,mixed>> */
    public function documentsByExtension(): array
    {
        return Db::fetchAll(
            'SELECT extension, COUNT(*) AS count, SUM(file_size) AS bytes
             FROM documents GROUP BY extension ORDER BY count DESC LIMIT 50'
        );
    }
}
