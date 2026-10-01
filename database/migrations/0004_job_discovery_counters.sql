-- =============================================================================
-- 0004: discovery counters on jobs
-- =============================================================================
-- Discovery skips files whose content is already stored (SHA-256 dedupe) or
-- whose current version is unchanged, and re-queues failed/parked versions.
-- These outcomes were invisible before (a job over a fully deduplicated folder
-- just finished with 0 documents). The counters make them visible in API/UI.
-- MariaDB supports ADD COLUMN IF NOT EXISTS, so re-running is harmless.
-- ---------------------------------------------------------------------------
ALTER TABLE jobs
  ADD COLUMN IF NOT EXISTS documents_skipped INT NOT NULL DEFAULT 0 AFTER documents_failed,
  ADD COLUMN IF NOT EXISTS documents_requeued INT NOT NULL DEFAULT 0 AFTER documents_skipped;
