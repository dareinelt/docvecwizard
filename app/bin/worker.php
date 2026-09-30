<?php

declare(strict_types=1);

/**
 * Worker: long-running CLI job processor.
 *
 *  - Atomically claims jobs (CREATED -> RUNNING) and documents
 *    (DISCOVERED/PENDING -> PROCESSING) so multiple replicas can run safely.
 *  - Recovers from crashes on startup by re-queueing stuck jobs/documents.
 *  - Handles SIGTERM/SIGINT for graceful shutdown (finishes the current
 *    document, then re-queues it so another run resumes).
 */

use App\Core\Config;
use App\Core\Db;
use App\Core\Logger;
use App\Services\EmbeddingClient;
use App\Services\JobService;
use App\Services\ModelService;
use App\Services\ProcessingService;

require __DIR__ . '/../config/bootstrap.php';

$log = Logger::channel('worker');
$stopping = false;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stopping): void {
        $stopping = true;
    });
    pcntl_signal(SIGINT, static function () use (&$stopping): void {
        $stopping = true;
    });
}

function finalizeJob(int $id): void
{
    (new JobService())->refreshCounters($id);
    $job = (new JobService())->getById($id);
    if ($job === null) {
        return;
    }
    if ((int) $job['documents_failed'] > 0 && (int) $job['documents_processed'] === 0) {
        (new JobService())->setStatus($id, JobService::STATUS_FAILED);
    } else {
        (new JobService())->setStatus($id, JobService::STATUS_COMPLETED);
    }
    Logger::channel('worker')->info('job finalized', [
        'job_id' => $job['job_id'],
        'status' => $job['status'],
    ]);
}

/** Recover jobs/documents left in a transitional state by a previous crash. */
function recoverStaleWork(): void
{
    Db::execute("UPDATE jobs SET status = 'CREATED', finished_at = NULL WHERE status = 'RUNNING'");
    Db::execute("UPDATE documents SET processing_status = 'DISCOVERED' WHERE processing_status = 'PROCESSING'");
}

$log->info('worker starting', ['pid' => getmypid()]);

// Sync model catalog from the embedding service (dimension metadata is
// authoritative and must never be hard-coded).
try {
    $synced = (new ModelService())->syncFromCatalog(new EmbeddingClient());
    $log->info('model catalog synced', ['models' => $synced]);
} catch (\Throwable $e) {
    $log->warning('model catalog sync failed; using DB metadata', ['error' => $e->getMessage()]);
}

recoverStaleWork();

$processor = new ProcessingService();
$jobService = new JobService();
$pollSeconds = (int) Config::string('WORKER_POLL_INTERVAL', '2');
$idleLogs = 0;

while (!$stopping) {
    $didWork = false;

    // 1. Promote the oldest CREATED job to RUNNING and discover its files.
    $job = Db::fetchOne("SELECT * FROM jobs WHERE status = 'CREATED' ORDER BY created_at ASC LIMIT 1");
    if ($job !== null) {
        $claimed = Db::execute(
            "UPDATE jobs SET status = 'RUNNING', started_at = COALESCE(started_at, NOW()) WHERE id = ? AND status = 'CREATED'",
            [(int) $job['id']]
        );
        if ($claimed > 0) {
            $job['status'] = 'RUNNING';
            try {
                $found = $processor->discover($job);
                $log->info('discovered documents', ['job_id' => $job['job_id'], 'new' => $found]);
            } catch (\Throwable $e) {
                $log->error('discovery failed', ['job_id' => $job['job_id'], 'error' => $e->getMessage()]);
                $jobService->setStatus((int) $job['id'], JobService::STATUS_FAILED);
            }
            $didWork = true;
        }
    }

    // 2. Process one pending document of any RUNNING job (atomic claim).
    $doc = Db::fetchOne(
        "SELECT d.* FROM documents d
         JOIN jobs j ON j.id = d.job_id
         WHERE j.status = 'RUNNING' AND d.processing_status IN ('DISCOVERED','PENDING')
         ORDER BY d.id ASC LIMIT 1"
    );
    if ($doc !== null && !$stopping) {
        $claimed = Db::execute(
            "UPDATE documents SET processing_status = 'PROCESSING' WHERE id = ? AND processing_status IN ('DISCOVERED','PENDING')",
            [(int) $doc['id']]
        );
        if ($claimed > 0) {
            $doc['processing_status'] = 'PROCESSING';
            $result = $processor->processDocument($doc);
            $log->info('document processed', [
                'document_id' => $doc['document_id'],
                'status' => $result['status'],
                'chunks' => $result['chunks'] ?? 0,
            ]);
            $didWork = true;
        }
    }

    // 3. Finalize RUNNING jobs that have no remaining work.
    $drainable = Db::fetchOne(
        "SELECT j.id, j.job_id FROM jobs j
         WHERE j.status = 'RUNNING'
           AND NOT EXISTS (SELECT 1 FROM documents d WHERE d.job_id = j.id AND d.processing_status IN ('DISCOVERED','PENDING','PROCESSING'))
         LIMIT 1"
    );
    if ($drainable !== null) {
        finalizeJob((int) $drainable['id']);
        $didWork = true;
    }

    // 4. Check for cancellation requests.
    Db::execute(
        "UPDATE documents d JOIN jobs j ON j.id = d.job_id
         SET d.processing_status = 'PENDING'
         WHERE j.status = 'CANCELLED' AND d.processing_status IN ('DISCOVERED','PROCESSING')"
    );

    if (!$didWork) {
        $idleLogs++;
        if ($idleLogs % 30 === 1) {
            $log->debug('worker idle');
        }
        sleep($pollSeconds);
    }
}

// Graceful shutdown: re-queue any document we may have left mid-flight.
Db::execute("UPDATE documents SET processing_status = 'DISCOVERED' WHERE processing_status = 'PROCESSING'");
$log->info('worker stopped gracefully');

exit(0);
