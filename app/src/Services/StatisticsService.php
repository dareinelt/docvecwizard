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
