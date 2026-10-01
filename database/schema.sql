-- =============================================================================
-- Document Embedding Manager - initial schema
-- =============================================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(64) PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------------
-- Key/value configuration (settings, chunking parameters, etc.)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(191) PRIMARY KEY,
  `value` TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Embedding models (dimension is stored, never hard-coded)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS embedding_models (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL UNIQUE,
  version VARCHAR(64) NOT NULL DEFAULT '1.0',
  repo VARCHAR(255) NOT NULL DEFAULT '',
  parameters VARCHAR(64) NOT NULL DEFAULT '',
  dimension INT NOT NULL,
  max_input_tokens INT NOT NULL DEFAULT 32768,
  normalization VARCHAR(32) NOT NULL DEFAULT 'l2',
  distance_metric VARCHAR(32) NOT NULL DEFAULT 'cosine',
  active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Jobs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id VARCHAR(36) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  source_directory VARCHAR(1024) NOT NULL,
  `recursive` TINYINT(1) NOT NULL DEFAULT 1,
  embedding_model VARCHAR(191) NOT NULL,
  embedding_dimension INT NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'CREATED',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  documents_total INT NOT NULL DEFAULT 0,
  documents_pending INT NOT NULL DEFAULT 0,
  documents_processing INT NOT NULL DEFAULT 0,
  documents_processed INT NOT NULL DEFAULT 0,
  documents_failed INT NOT NULL DEFAULT 0,
  chunks_total INT NOT NULL DEFAULT 0,
  vectors_total INT NOT NULL DEFAULT 0,
  tokens_total BIGINT NOT NULL DEFAULT 0,
  bytes_total BIGINT NOT NULL DEFAULT 0,
  error_count INT NOT NULL DEFAULT 0,
  INDEX idx_jobs_status (status),
  INDEX idx_jobs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Documents
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id VARCHAR(36) NOT NULL,
  document_version_id VARCHAR(36) NOT NULL,
  version INT NOT NULL DEFAULT 1,
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  job_id BIGINT UNSIGNED NULL,
  source_path VARCHAR(1024) NOT NULL,
  relative_path VARCHAR(1024) NOT NULL DEFAULT '',
  filename VARCHAR(512) NOT NULL,
  extension VARCHAR(32) NOT NULL,
  mime_type VARCHAR(128) NOT NULL DEFAULT '',
  file_size BIGINT NOT NULL DEFAULT 0,
  file_hash CHAR(64) NOT NULL,
  blob_id BIGINT UNSIGNED NULL,
  created_at DATETIME NULL,
  modified_at DATETIME NULL,
  indexed_at DATETIME NULL,
  page_count INT NOT NULL DEFAULT 0,
  character_count BIGINT NOT NULL DEFAULT 0,
  word_count BIGINT NOT NULL DEFAULT 0,
  token_count_estimate BIGINT NOT NULL DEFAULT 0,
  chunk_count INT NOT NULL DEFAULT 0,
  embedding_model VARCHAR(191) NOT NULL DEFAULT '',
  embedding_dimension INT NOT NULL DEFAULT 0,
  processing_status VARCHAR(32) NOT NULL DEFAULT 'DISCOVERED',
  processing_duration INT NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  metadata JSON NULL,
  CONSTRAINT fk_documents_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL,
  UNIQUE INDEX idx_documents_version_id (document_version_id),
  INDEX idx_documents_hash (file_hash),
  INDEX idx_documents_job (job_id),
  INDEX idx_documents_status (processing_status),
  INDEX idx_documents_filename (filename),
  INDEX idx_documents_group_current (document_id, version),
  INDEX idx_documents_is_current (is_current),
  INDEX idx_documents_blob (blob_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Original document payloads (Base64). One blob per document version.
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
  UNIQUE INDEX idx_blobs_version (document_version_id),
  INDEX idx_blobs_document (document_id),
  INDEX idx_blobs_sha256 (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Document chunks (text kept locally for retrieval / export)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_chunks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id BIGINT UNSIGNED NOT NULL,
  chunk_id VARCHAR(36) NOT NULL UNIQUE,
  vector_id VARCHAR(36) NULL,
  chunk_index INT NOT NULL,
  page_start INT NOT NULL DEFAULT 0,
  page_end INT NOT NULL DEFAULT 0,
  text_length INT NOT NULL DEFAULT 0,
  token_count INT NOT NULL DEFAULT 0,
  text MEDIUMTEXT NOT NULL,
  CONSTRAINT fk_chunks_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  INDEX idx_chunks_document (document_id),
  INDEX idx_chunks_vector (vector_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Processing errors
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS processing_errors (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT UNSIGNED NULL,
  document_id BIGINT UNSIGNED NULL,
  step VARCHAR(64) NOT NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_errors_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_errors_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  INDEX idx_errors_job (job_id),
  INDEX idx_errors_document (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- System metrics (persistent snapshots)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_metrics (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service VARCHAR(64) NOT NULL,
  metric VARCHAR(64) NOT NULL,
  value DOUBLE NOT NULL,
  meta JSON NULL,
  recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_metrics_service (service),
  INDEX idx_metrics_time (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Audit log
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(128) NOT NULL,
  entity_type VARCHAR(64) NULL,
  entity_id VARCHAR(64) NULL,
  details JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- TLS certificates (CSR / cert / fallback workflow)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tls_certificates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(16) NOT NULL,
  common_name VARCHAR(255) NOT NULL DEFAULT '',
  san TEXT NULL,
  subject JSON NULL,
  issuer JSON NULL,
  not_before DATETIME NULL,
  not_after DATETIME NULL,
  fingerprint VARCHAR(128) NOT NULL DEFAULT '',
  key_type VARCHAR(32) NOT NULL DEFAULT '',
  private_key_enc TEXT NULL,
  csr_pem TEXT NULL,
  cert_pem TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Exports
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS exports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  export_id VARCHAR(36) NOT NULL UNIQUE,
  filename VARCHAR(255) NOT NULL DEFAULT '',
  `format` VARCHAR(32) NOT NULL DEFAULT 'tar.gz',
  status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
  manifest JSON NULL,
  checksum CHAR(64) NOT NULL DEFAULT '',
  file_size BIGINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Users. Passwords are stored exclusively as password_hash() output
-- (Argon2id, bcrypt fallback) - never in plain text.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Failed login attempts (sliding-window brute-force protection).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(191) NOT NULL,
  attempted_at DATETIME NOT NULL,
  INDEX idx_login_attempts_user (username, attempted_at),
  INDEX idx_login_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
