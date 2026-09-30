<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Uuid;

final class JobService
{
    public const STATUS_CREATED = 'CREATED';
    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_PAUSED = 'PAUSED';

    /**
     * @param array{name:string,source_directory:string,recursive:bool,embedding_model:string} $input
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $modelService = new ModelService();
        $model = $modelService->findByName($input['embedding_model']);
        if ($model === null) {
            throw new \InvalidArgumentException('Unknown embedding model: ' . $input['embedding_model']);
        }
        $jobId = Uuid::v4();
        Db::execute(
            'INSERT INTO jobs (job_id, name, source_directory, `recursive`, embedding_model, embedding_dimension, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $jobId,
                $input['name'],
                $input['source_directory'],
                $input['recursive'] ? 1 : 0,
                $model['name'],
                (int) $model['dimension'],
                self::STATUS_CREATED,
            ]
        );

        return $this->getByUuid($jobId) ?? ['job_id' => $jobId];
    }

    /** @return list<array<string,mixed>> */
    public function list(int $limit = 100, int $offset = 0): array
    {
        return Db::fetchAll(
            'SELECT * FROM jobs ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
    }

    /** @return array<string,mixed>|null */
    public function getByUuid(string $jobId): ?array
    {
        return Db::fetchOne('SELECT * FROM jobs WHERE job_id = ?', [$jobId]);
    }

    /** @return array<string,mixed>|null */
    public function getById(int $id): ?array
    {
        return Db::fetchOne('SELECT * FROM jobs WHERE id = ?', [$id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $now = gmdate('Y-m-d H:i:s');
        if ($status === self::STATUS_RUNNING) {
            Db::execute('UPDATE jobs SET status = ?, started_at = COALESCE(started_at, ?) WHERE id = ?', [$status, $now, $id]);
        } elseif ($status === self::STATUS_COMPLETED || $status === self::STATUS_FAILED || $status === self::STATUS_CANCELLED) {
            Db::execute('UPDATE jobs SET status = ?, finished_at = ? WHERE id = ?', [$status, $now, $id]);
        } else {
            Db::execute('UPDATE jobs SET status = ? WHERE id = ?', [$status, $id]);
        }
    }

    /** Cancel a running/queued job by id. */
    public function cancel(int $id): void
    {
        $job = $this->getById($id);
        if ($job === null) {
            throw new \InvalidArgumentException('Job not found');
        }
        if (in_array($job['status'], [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
            return;
        }
        $this->setStatus($id, self::STATUS_CANCELLED);
    }

    /** Recompute aggregate counters for a job. */
    public function refreshCounters(int $id): void
    {
        $counts = Db::fetchOne(
            'SELECT
                COUNT(*) AS documents_total,
                SUM(processing_status = "DISCOVERED" OR processing_status = "PENDING") AS documents_pending,
                SUM(processing_status = "PROCESSING") AS documents_processing,
                SUM(processing_status = "COMPLETED") AS documents_processed,
                SUM(processing_status = "FAILED") AS documents_failed,
                SUM(chunk_count) AS chunks_total,
                SUM(CASE WHEN processing_status = "COMPLETED" THEN chunk_count ELSE 0 END) AS vectors_total,
                SUM(token_count_estimate) AS tokens_total,
                SUM(file_size) AS bytes_total
             FROM documents WHERE job_id = ?',
            [$id]
        );
        if ($counts === null) {
            return;
        }
        Db::execute(
            'UPDATE jobs SET documents_total=?, documents_pending=?, documents_processing=?,
                    documents_processed=?, documents_failed=?, chunks_total=?, vectors_total=?, tokens_total=?, bytes_total=?,
                    error_count=(SELECT COUNT(*) FROM processing_errors WHERE job_id=?)
             WHERE id=?',
            [
                (int) $counts['documents_total'], (int) $counts['documents_pending'],
                (int) $counts['documents_processing'], (int) $counts['documents_processed'],
                (int) $counts['documents_failed'], (int) $counts['chunks_total'],
                (int) $counts['vectors_total'], (int) $counts['tokens_total'],
                (int) $counts['bytes_total'], $id, $id,
            ]
        );
    }
}
