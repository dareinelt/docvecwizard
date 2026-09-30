-- =============================================================================
-- 0002 - Original document storage (Base64) + document versioning
-- =============================================================================
-- Stores every processed document's original file byte-for-byte as Base64 in a
-- dedicated `document_blobs` table (LONGTEXT), so originals survive processing
-- and are recoverable without information loss. Adds versioning columns to
-- `documents` and a stable `vector_id` link to `document_chunks`.
--
-- Idempotency: every statement is guarded (CREATE ... IF NOT EXISTS /
-- ADD COLUMN IF NOT EXISTS / ADD INDEX IF NOT EXISTS / DROP INDEX IF EXISTS)
-- because MySQL/MariaDB DDL performs implicit commits and a partial run must
-- re-apply cleanly.

-- ---------------------------------------------------------------------------
-- Original file payloads (Base64). One blob per document version.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_blobs (
  blob_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id VARCHAR(36) NOT NULL,
  document_version_id VARCHAR(36) NOT NULL,
  encoding VARCHAR(32) NOT NULL DEFAULT 'base64',
  mime_type VARCHAR(128) NOT NULL DEFAULT '',
  original_filename VARCHAR(512) NOT NULL DEFAULT '',
  file_size BIGINT NOT NULL DEFAULT 0,
  sha256 CHAR(64) NOT NULL,
  base64_data LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_blobs_version (document_version_id),
  INDEX idx_blobs_document (document_id),
  INDEX idx_blobs_sha256 (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Versioning columns on documents.
-- ---------------------------------------------------------------------------
ALTER TABLE documents ADD COLUMN IF NOT EXISTS document_version_id VARCHAR(36) NULL AFTER document_id;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS version INT NOT NULL DEFAULT 1 AFTER document_version_id;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS is_current TINYINT(1) NOT NULL DEFAULT 1 AFTER version;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS blob_id BIGINT UNSIGNED NULL AFTER file_hash;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS metadata JSON NULL AFTER error_message;

-- Backfill: existing rows become version 1 of their logical document.
UPDATE documents SET document_version_id = document_id WHERE document_version_id IS NULL OR document_version_id = '';
ALTER TABLE documents MODIFY document_version_id VARCHAR(36) NOT NULL;

-- `document_id` becomes the logical (grouping) ID; a unique version ID is the
-- precise identity of each stored version. The auto-created UNIQUE index on
-- `document_id` must be dropped to allow multiple versions per logical document.
DROP INDEX IF EXISTS document_id ON documents;
ALTER TABLE documents ADD UNIQUE INDEX IF NOT EXISTS idx_documents_version_id (document_version_id);
ALTER TABLE documents ADD INDEX IF NOT EXISTS idx_documents_group_current (document_id, version);
ALTER TABLE documents ADD INDEX IF NOT EXISTS idx_documents_is_current (is_current);
ALTER TABLE documents ADD INDEX IF NOT EXISTS idx_documents_blob (blob_id);

-- ---------------------------------------------------------------------------
-- Stable vector link on chunks (mirrors the Milvus primary key).
-- ---------------------------------------------------------------------------
ALTER TABLE document_chunks ADD COLUMN IF NOT EXISTS vector_id VARCHAR(36) NULL AFTER chunk_id;
ALTER TABLE document_chunks ADD INDEX IF NOT EXISTS idx_chunks_vector (vector_id);
