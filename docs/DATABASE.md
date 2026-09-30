# Datenbankschema

## Übersicht

Das System verwendet **MariaDB 11.4** für alle relationalen Daten. Die
Vektoren selbst liegen in **Milvus** (siehe [MILVUS.md](MILVUS.md)).

Das Schema liegt an zwei Stellen vor (identischer Inhalt):

- `app/migrations/0001_initial.sql` – von `migrate` ausgeführt
- `database/schema.sql` – Dokumentations-/Referenzkopie
- `database/migrations/0001_initial.sql` – Spiegel der Migration

## Migration

Der `migrate`-Dienst führt beim Start `app/bin/migrate.php` aus und beendet
sich danach (`exit 0`). Migrationen werden in der Tabelle `schema_migrations`
versioniert.

## Tabellen

### `schema_migrations`

| Spalte | Typ | Beschreibung |
| --- | --- | --- |
| version | VARCHAR(64) PK | Versionsname |
| applied_at | DATETIME | Zeitpunkt |

### `settings`

Key/Value-Konfiguration (Laufzeit-Einstellungen, Chunking, Modell).

| Spalte | Typ | Beschreibung |
| --- | --- | --- |
| key | VARCHAR(191) PK | Schlüssel |
| value | TEXT NULL | Wert |
| updated_at | DATETIME | letzte Änderung |

### `embedding_models`

| Spalte | Typ | Beschreibung |
| --- | --- | --- |
| id | INT UNSIGNED PK | |
| name | VARCHAR(191) UNIQUE | Modellname |
| version | VARCHAR(64) | Katalogversion |
| repo | VARCHAR(255) | Hugging-Face-Repo |
| parameters | VARCHAR(64) | Parameter (0.6B/4B/8B) |
| dimension | INT | Vektordimension |
| max_input_tokens | INT | max. Tokens |
| normalization | VARCHAR(32) | Normalisierung (l2) |
| distance_metric | VARCHAR(32) | Distanzmetrik (cosine) |
| active | TINYINT(1) | aktiv? |
| created_at / updated_at | DATETIME | |

### `jobs`

| Spalte | Typ | Beschreibung |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK | |
| job_id | VARCHAR(36) UNIQUE | UUID |
| name | VARCHAR(255) | Jobname |
| source_directory | VARCHAR(1024) | Quellverzeichnis |
| recursive | TINYINT(1) | rekursiv? |
| embedding_model | VARCHAR(191) | Modellname |
| embedding_dimension | INT | Dimension |
| status | VARCHAR(32) | CREATED/RUNNING/COMPLETED/FAILED/CANCELLED/PAUSED |
| created_at / started_at / finished_at | DATETIME | |
| documents_total / pending / processing / processed / failed | INT | Zähler |
| chunks_total / vectors_total | INT | |
| tokens_total / bytes_total | BIGINT | |
| error_count | INT | |

### `documents`

| Spalte | Typ | Beschreibung |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK | |
| document_id | VARCHAR(36) UNIQUE | UUID |
| job_id | BIGINT UNSIGNED NULL FK | zugehöriger Job |
| source_path / relative_path | VARCHAR(1024) | Pfade |
| filename | VARCHAR(512) | Dateiname |
| extension | VARCHAR(32) | Endung |
| mime_type | VARCHAR(128) | MIME-Typ |
| file_size | BIGINT | Größe |
| file_hash | CHAR(64) | SHA-256 (Dedupe) |
| created_at / modified_at / indexed_at | DATETIME | |
| page_count | INT | Seiten |
| character_count / word_count | BIGINT | |
| token_count_estimate | BIGINT | geschätzte Tokens |
| chunk_count | INT | Anzahl Chunks |
| embedding_model | VARCHAR(191) | Modell |
| embedding_dimension | INT | Dimension |
| processing_status | VARCHAR(32) | DISCOVERED/PROCESSING/COMPLETED/FAILED |
| processing_duration | INT | Dauer (s) |
| error_message | TEXT NULL | Fehlertext |

### `document_chunks`

| Spalte | Typ | Beschreibung |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK | |
| document_id | BIGINT UNSIGNED FK | Dokument |
| chunk_id | VARCHAR(36) UNIQUE | UUID |
| chunk_index | INT | Position |
| page_start / page_end | INT | Seiten |
| text_length / token_count | INT | |
| text | MEDIUMTEXT | Chunk-Text (lokal für Export) |

### `processing_errors`

| Spalte | Typ | Beschreibung |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK | |
| job_id / document_id | BIGINT FK NULL | |
| step | VARCHAR(64) | Verarbeitungsschritt |
| error_message | TEXT NULL | |
| created_at | DATETIME | |

### `system_metrics`

Persistente Metrik-Snapshots.

| Spalte | Typ | Beschreibung |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK | |
| service | VARCHAR(64) | Dienstname |
| metric | VARCHAR(64) | Metrikname |
| value | DOUBLE | Wert |
| meta | JSON NULL | Zusatzdaten |
| recorded_at | DATETIME | |

### `audit_log`

| Spalte | Typ | Beschreibung |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK | |
| action | VARCHAR(128) | Aktion |
| entity_type / entity_id | VARCHAR(64) | betroffene Entität |
| details | JSON NULL | Details |
| created_at | DATETIME | |

### `tls_certificates`

| Spalte | Typ | Beschreibung |
| --- | --- | --- |
| id | INT UNSIGNED PK | |
| kind | VARCHAR(16) | Art (csr/selfsigned/import) |
| common_name | VARCHAR(255) | CN |
| san | TEXT NULL | Subject Alternative Names |
| subject / issuer | JSON NULL | |
| not_before / not_after | DATETIME | Gültigkeit |
| fingerprint | VARCHAR(128) | Fingerprint |
| key_type | VARCHAR(32) | rsa2048/3072/4096/ec256/ec384 |
| private_key_enc | TEXT NULL | verschlüsselter Privatschlüssel |
| csr_pem | TEXT NULL | CSR |
| cert_pem | TEXT NULL | Zertifikat |
| active | TINYINT(1) | aktiv? |
| created_at / updated_at | DATETIME | |

### `exports`

| Spalte | Typ | Beschreibung |
| --- | --- | --- |
| id | BIGINT UNSIGNED PK | |
| export_id | VARCHAR(36) UNIQUE | UUID |
| filename | VARCHAR(255) | Dateiname |
| format | VARCHAR(32) | tar.zst |
| status | VARCHAR(32) | PENDING/COMPLETED/FAILED |
| manifest | JSON NULL | Manifest |
| checksum | CHAR(64) | SHA-256 |
| file_size | BIGINT | |
| created_at / finished_at | DATETIME | |

## ER-Diagramm (vereinfacht)

```text
jobs 1 ── N documents 1 ── N document_chunks
                 │
                 └── N processing_errors

embedding_models (referenziert per Name, nicht FK)
tls_certificates, exports, settings, system_metrics, audit_log (eigenständig)
```

## Hinweise

- Die Dokument-Deduplizierung erfolgt über `file_hash` (SHA-256).
- `documents.processing_status` (nicht `status`) beschreibt den
  Verarbeitungszustand; `jobs.status` den Job-Zustand.
- Chunk-Texte werden in `document_chunks.text` lokal vorgehalten (für
  Export/Retrieval); die Vektoren liegen nur in Milvus.
