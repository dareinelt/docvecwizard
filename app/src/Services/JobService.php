<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Uuid;
use App\Domain\JobStatus;
use App\Security\PathGuard;

final class JobService
{
    public const STATUS_CREATED = JobStatus::Created->value;
    public const STATUS_RUNNING = JobStatus::Running->value;
    public const STATUS_COMPLETED = JobStatus::Completed->value;
    public const STATUS_FAILED = JobStatus::Failed->value;
    public const STATUS_CANCELLED = JobStatus::Cancelled->value;
    public const STATUS_PAUSED = JobStatus::Paused->value;

    /**
     * @param array{name:string,source_directory:string,recursive:bool,embedding_model:string} $input
     * @return array<string,mixed>
     * @throws \InvalidArgumentException on invalid input (message is user-facing)
     */
    public function create(array $input): array
    {
        if (trim($input['embedding_model']) === '') {
            throw new \InvalidArgumentException('Bitte ein Embedding-Modell wählen.');
        }
        $modelService = new ModelService();
        $model = $modelService->findByName($input['embedding_model']);
        if ($model === null) {
            throw new \InvalidArgumentException('Unknown embedding model: ' . $input['embedding_model']);
        }

        // Validate the source directory up front. Previously any string was
        // accepted and the job only failed later inside the worker.
        $source = PathGuard::normalise($input['source_directory']);
        $absolute = PathGuard::resolve(Config::string('INPUT_ROOT', '/srv/data/input'), $source);
        if (!is_dir($absolute)) {
            throw new \InvalidArgumentException('Quellordner existiert nicht: ' . ($source === '' ? '/' : $source));
        }

        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $input['name']) ?? '');
        if ($name === '') {
            $name = 'Import ' . gmdate('Y-m-d H:i');
        }
        $name = mb_substr($name, 0, 255);

        $jobId = Uuid::v4();
        Db::execute(
            'INSERT INTO jobs (job_id, name, source_directory, `recursive`, embedding_model, embedding_dimension, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $jobId,
                $name,
                $source,
                $input['recursive'] ? 1 : 0,
                $model['name'],
                (int) $model['dimension'],
                self::STATUS_CREATED,
            ]
        );
        Audit::record('job.create', 'job', $jobId, ['source_directory' => $source]);

        return $this->getByUuid($jobId) ?? ['job_id' => $jobId];
    }

    /**
     * Re-process a single FAILED document version. Creates a small job that
     * starts directly in RUNNING (no discovery pass) and attaches the current
     * version to it; the worker then picks it up like any discovered document.
     *
     * @param array<string,mixed> $doc current version row from `documents`
     * @return array<string,mixed> the new job
     * @throws \InvalidArgumentException with a user-facing (German) message
     */
    public function retryDocument(array $doc): array
    {
        if ((int) ($doc['is_current'] ?? 0) !== 1 || (string) $doc['processing_status'] !== 'FAILED') {
            throw new \InvalidArgumentException('Nur fehlgeschlagene aktuelle Dokumentversionen können erneut verarbeitet werden.');
        }
        $sourcePath = (string) $doc['source_path'];
        if (!is_file($sourcePath)) {
            throw new \InvalidArgumentException('Die Quelldatei existiert nicht mehr: ' . basename($sourcePath));
        }
        $modelService = new ModelService();
        $model = $modelService->findByName((string) $doc['embedding_model']) ?? $modelService->active();
        if ($model === null) {
            throw new \InvalidArgumentException('Kein Embedding-Modell verfügbar.');
        }

        $root = rtrim(Config::string('INPUT_ROOT', '/srv/data/input'), '/');
        $dir = dirname($sourcePath);
        $source = str_starts_with($dir, $root . '/') ? substr($dir, strlen($root) + 1) : ($dir === $root ? '' : $dir);

        $jobId = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        Db::execute(
            'INSERT INTO jobs (job_id, name, source_directory, `recursive`, embedding_model, embedding_dimension, status, started_at)
             VALUES (?, ?, ?, 0, ?, ?, ?, ?)',
            [
                $jobId,
                mb_substr('Erneut: ' . (string) $doc['filename'], 0, 255),
                $source,
                $model['name'],
                (int) $model['dimension'],
                self::STATUS_RUNNING,
                $now,
            ]
        );
        $job = $this->getByUuid($jobId);
        if ($job === null) {
            throw new \RuntimeException('Auftrag konnte nicht angelegt werden.');
        }
        (new ProcessingService())->requeue($job, $doc);
        $this->refreshCounters((int) $job['id']);
        if ((int) $doc['job_id'] !== (int) $job['id']) {
            $this->refreshCounters((int) $doc['job_id']);
        }
        Audit::record('document.retry', 'document', (string) $doc['document_id'], ['job_id' => $jobId]);

        return $this->getByUuid($jobId) ?? $job;
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
        $enum = JobStatus::from($status);
        if ($enum === JobStatus::Running) {
            Db::execute('UPDATE jobs SET status = ?, started_at = COALESCE(started_at, ?) WHERE id = ?', [$status, $now, $id]);
        } elseif ($enum->isTerminal()) {
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
        if ((JobStatus::tryFrom((string) $job['status']) ?? JobStatus::Created)->isTerminal()) {
            return;
        }
        $this->setStatus($id, self::STATUS_CANCELLED);
        Audit::record('job.cancel', 'job', (string) $job['job_id']);
    }

    /** Recompute aggregate counters for a job. */
    public function refreshCounters(int $id): void
    {
        // Version-aware counters: the logical document set is counted by
        // distinct `document_id`, while status/storage aggregates reflect the
        // *current* version only (old versions are immutable history).
        $counts = Db::fetchOne(
            'SELECT
                COUNT(DISTINCT document_id) AS documents_total,
                SUM(is_current = 1 AND (processing_status = "DISCOVERED" OR processing_status = "PENDING")) AS documents_pending,
                SUM(is_current = 1 AND processing_status = "PROCESSING") AS documents_processing,
                SUM(is_current = 1 AND processing_status = "COMPLETED") AS documents_processed,
                SUM(is_current = 1 AND processing_status = "FAILED") AS documents_failed,
                SUM(CASE WHEN is_current = 1 THEN chunk_count ELSE 0 END) AS chunks_total,
                SUM(CASE WHEN is_current = 1 AND processing_status = "COMPLETED" THEN chunk_count ELSE 0 END) AS vectors_total,
                SUM(CASE WHEN is_current = 1 THEN token_count_estimate ELSE 0 END) AS tokens_total,
                SUM(CASE WHEN is_current = 1 THEN file_size ELSE 0 END) AS bytes_total
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
