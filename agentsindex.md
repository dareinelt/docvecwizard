# Agent's Index – DocVecWizard

> Wissensbasis für Coding-Agenten. Erstellt durch Analyse des Quellcodes (Stand: Branch `dareinelt-clarify-open-questions-improvements` nach den Paketen P1–P12, aufbauend auf PR #4).
> Kennzeichnung: **[Fakt]** = direkt im Code/Config belegt · **[Abgeleitet]** = aus mehreren Stellen geschlossen · **[Annahme]** = nicht verifiziert.
> Ohne Kennzeichnung gilt: Fakt, mit Datei-Referenz.
> Bei Widerspruch zwischen `docs/*.md` und Code gilt **der Code**. Bekannte Abweichungen: siehe [Open Questions](#open-questions) und [Technical Debt](#technical-debt).

## Inhaltsverzeichnis

1. [Project Overview](#project-overview)
2. [Repository Structure](#repository-structure)
3. [Technology Stack](#technology-stack)
4. [Architecture](#architecture)
5. [Core Components](#core-components)
6. [Entry Points](#entry-points)
7. [Data Model](#data-model)
8. [Data Flows](#data-flows)
9. [Business Logic](#business-logic)
10. [APIs and Integrations](#apis-and-integrations)
11. [Configuration](#configuration)
12. [Authentication and Authorization](#authentication-and-authorization)
13. [Error Handling](#error-handling)
14. [Logging and Observability](#logging-and-observability)
15. [Testing](#testing)
16. [Build and Development](#build-and-development)
17. [CI/CD](#cicd)
18. [Important Invariants](#important-invariants)
19. [Danger Zones](#danger-zones)
20. [Change Map](#change-map)
21. [Where to Look First](#where-to-look-first)
22. [Technical Debt](#technical-debt)
23. [Architectural Decisions](#architectural-decisions)
24. [Historical Context](#historical-context)
25. [Open Questions](#open-questions)
26. [Agent Operating Guidelines](#agent-operating-guidelines)

---

## Project Overview

**DocVecWizard** („Document Embedding Manager“) ist ein selbst gehosteter Docker-Compose-Stack, der Dokumente (PDF, Office, Text, …) aus einem Eingangsverzeichnis einliest, in Text konvertiert, in Chunks zerlegt, mit lokalen Qwen3-Embedding-Modellen vektorisiert und in **Milvus** speichert. Metadaten, Originaldateien (Base64), Chunks, Jobs, Benutzer und Audit-Log liegen in **MariaDB**. Eine Single-Page-Web-UI (deutsch) erlaubt Upload, Jobsteuerung, semantische Suche, Export/Import, Integritätsprüfung und TLS-Verwaltung.

- Zielgruppe [Abgeleitet]: Betreiber eines internen/offline RAG-Vorverarbeitungs-Servers, ein einziger Admin-Benutzertyp (keine Rollen).
- Betriebsmodell: vollständig lokal; nur Container `web` veröffentlicht einen Port (`${HTTPS_PORT}:443`) ([docker-compose.yml](docker-compose.yml)).
- Sprache: UI-Texte, Fehlermeldungen an Nutzer und `docs/` sind **deutsch**; Code und Code-Kommentare englisch.
- Ursprung: Generiert aus den Spezifikations-Prompts [.agent/job.md](.agent/job.md) (Hauptspezifikation) und [.agent/ext1.md](.agent/ext1.md) (Erweiterung: Base64-Originale, Versionierung, Export-Roundtrip).

## Repository Structure

```
/
├── docker-compose.yml        # Gesamter Stack (8 Services + model-download im Profil tools), Netzwerke, Volumes
├── .env.example              # Alle Umgebungsvariablen mit Defaults (keine echten Secrets)
├── .gitattributes            # Erzwingt LF (CRLF hatte Entrypoints gebrochen)
├── .github/workflows/ci.yml  # CI: php -l, Unit-Tests, node --check, py_compile, compose config, Migrationsabgleich
├── app/                      # PHP-Backend (FPM) – API, Worker, Migrationen
│   ├── Dockerfile            # php:8.3-fpm-alpine, User app (10003)
│   ├── docker-entrypoint.sh  # schreibt PHP-ini (Upload-Limits, PHP_MEMORY_LIMIT), Verzeichnisse, su-exec
│   ├── php-fpm.d/www.conf    # FPM-Pool (clear_env = no!)
│   ├── bin/                  # CLI: worker.php, migrate.php, set-password.php, healthcheck.php
│   ├── config/bootstrap.php  # Autoloader, Error-Handler, Config + DB-Init
│   ├── public/index.php      # Einziger HTTP-Einstiegspunkt (Front Controller)
│   ├── migrations/*.sql      # AUSGEFÜHRTE Migrationen (0001–0004)
│   └── src/
│       ├── Core/             # Config, Db, Logger(Instance), Uuid, Base64
│       ├── Domain/           # JobStatus (Enum)
│       ├── Http/             # Request, Response, Router, HttpException, JsonClient, UpstreamException, UploadedFile
│       ├── Controllers/      # Routes.php (Routentabelle) + Controller je Bereich
│       ├── Security/         # AccessGuard, Auth, Session, Csrf, LoginThrottle, PathGuard
│       └── Services/         # Geschäftslogik + Clients (Milvus, Embedding, Converter), Audit, Chunker
├── database/                 # NUR Referenz: schema.sql (konsolidiert) + Kopie der Migrationen
├── converter/                # Python/FastAPI: Datei → Text (pandoc, pdftotext, LibreOffice)
├── embedding/                # Python/FastAPI: Text → Vektor (torch/transformers, CPU)
│   ├── catalog.json          # Modellkatalog (Qwen3-Embedding 0.6B/4B/8B)
│   ├── scripts/download-models.py  # läuft im Service model-download (Profil tools, Netz tools)
│   └── models/               # Bind-Mount /models (Modelle, gitignored)
├── web/                      # nginx 1.27 + statische SPA
│   ├── nginx.conf, security-headers.conf, docker-entrypoint.sh (Self-signed-Fallback, Cert-Reload)
│   └── html/                 # index.html, assets/js/app.js (~1190 Zeilen), assets/css/app.css
├── scripts/                  # backup-db.sh, backup-milvus.sh, check-health.sh, status.sh
├── tests/                    # unit/ (lokal), integration/ (im Container), smoke-test.sh, fixtures/
├── docs/                     # Betriebs-/Architektur-Doku (deutsch), AUDIT_REPORT.md, manual.pdf
└── .agent/                   # Ursprüngliche Generierungs-Prompts (job.md, ext1.md)
```

Laufzeitdaten (gitignored, Bind-Mounts): `data/{input,staging,exports}` (`${DATA_DIR:-./data}`), `web/ssl/` (aktives Zertifikat, geteilt zwischen `app` (`/srv/ssl`) und `web` (`/etc/nginx/ssl`)), `embedding/models/`.

## Technology Stack

| Bereich | Technologie | Version / Quelle |
|---|---|---|
| Backend | PHP (FPM), **kein Framework, kein Composer** | `php:8.3-fpm-alpine` ([app/Dockerfile](app/Dockerfile)) |
| PHP-Extensions | pdo_mysql, mbstring, fileinfo, zip, pcntl, posix, curl | app/Dockerfile |
| Autoload | eigener PSR-4-artiger Autoloader `App\` → `app/src/` | [app/config/bootstrap.php](app/config/bootstrap.php) |
| Relationale DB | MariaDB (utf8mb4, `max-allowed-packet=256M`) | `mariadb:11.4` |
| Vektor-DB | Milvus standalone (embedded etcd, lokaler Storage), REST v2 | `milvusdb/milvus:v2.5.27` |
| Embedding | Python 3.11, FastAPI 0.115.6, uvicorn 0.34.0, torch 2.4.1 (CPU), transformers 4.51.3, numpy 1.26.4, huggingface_hub 0.30.2 | [embedding/Dockerfile](embedding/Dockerfile), [embedding/requirements.txt](embedding/requirements.txt) |
| Converter | Debian bookworm, pandoc, poppler-utils (pdftotext), LibreOffice (writer/calc/impress), tini, FastAPI/uvicorn | [converter/Dockerfile](converter/Dockerfile) |
| Webserver | nginx + openssl/curl | `nginx:1.27-alpine` ([web/Dockerfile](web/Dockerfile)) |
| Frontend | Vanilla JS SPA (Hash-Routing), keine Build-Toolchain, strikte CSP | [web/html/assets/js/app.js](web/html/assets/js/app.js) |
| Orchestrierung | Docker Compose (Projektname `docvecwizard`) | docker-compose.yml |
| Tests | eigener Mini-Testrunner (PHP), Bash-Smoke-Test | [tests/](tests/) |
| Linter/Formatter/Static Analysis | **keine konfiguriert** | – |

## Architecture

### Container und Netzwerke

```mermaid
flowchart LR
  user((Browser)) -- "HTTPS ${HTTPS_PORT}:443" --> web
  subgraph frontend ["Netz frontend (bridge)"]
    web["web: nginx + SPA"]
  end
  subgraph backend ["Netz backend (internal: true, kein Internet)"]
    worker["worker: php bin/worker.php"]
    migrate["migrate (one-shot)"]
    db[("db: MariaDB")]
    milvus[("milvus: 19530 REST / 9091 health")]
    embedding["embedding: FastAPI 8000"]
    converter["converter: FastAPI 8001"]
  end
  subgraph tools ["Netz tools (Internet; Profil tools)"]
    modeldl["model-download (one-shot) → embedding/models/"]
  end
  app["app: PHP-FPM 9000 (frontend + backend)"]
  web -- "FastCGI /api/, /healthz" --> app
  app --> db & milvus & embedding & converter
  worker --> db & milvus & embedding & converter
  migrate --> db
  modeldl -. "Volume embedding/models" .-> embedding
```

- `app` hängt an `frontend` **und** `backend`; `worker`, `db`, `milvus`, `embedding`, `converter`, `migrate` nur an `backend` [Fakt, docker-compose.yml].
- Modellgewichte kommen **nicht** aus dem laufenden Stack: `docker compose --profile tools run --rm model-download` (Netz `tools`, Proxy-Variablen) füllt `embedding/models/`; `embedding` meldet ohne Modell `/health` = degraded und `SystemService` verlangt `model_loaded` (P1).
- Startreihenfolge: `db` healthy → `migrate` completed → `app` (und `worker`, zusätzlich `embedding` healthy) → `web` (wartet auf `app` healthy).
- `app` und `worker` nutzen **dasselbe Image** (`./app`), `worker` startet `php bin/worker.php`; `stop_grace_period: 60s`.
- Geteilte Bind-Mounts: `${DATA_DIR}/input|staging|exports` → `/srv/data/...` (app/worker rw; converter: `input` **ro**, `staging` rw, kein `exports`), `./web/ssl` → app `/srv/ssl`, web `/etc/nginx/ssl` (worker hat keinen TLS-Mount).

### Schichten im PHP-Backend

```
public/index.php ─► config/bootstrap.php (Config, Db, Logger, Error-Handler)
      │
      ▼
Http\Router ──(vor jedem Handler)──► Security\AccessGuard (Session, Auth, CSRF)
      │
      ▼
Controllers\* (dünn: Parameter lesen, Service aufrufen, Response::json)
      │
      ▼
Services\* (Geschäftslogik, PDO direkt via Core\Db, HTTP-Clients zu Milvus/Embedding/Converter)
```

- Keine DI-Container: Services werden in Controllern/Worker per `new` erzeugt; DB-Zugriff über statisches `Core\Db::pdo()` [Fakt].
- Kein ORM: rohes PDO mit Prepared Statements.
- Globaler statischer Zustand: `Config` (aus `getenv()`), `Db`, `Logger`, `Auth::$user`-Cache.

### Request-Pipeline (HTTP)

```mermaid
sequenceDiagram
  participant B as Browser (app.js)
  participant N as nginx
  participant I as index.php
  participant R as Router
  participant G as AccessGuard
  participant C as Controller/Service
  B->>N: /api/... (Cookie docvec_sid, X-CSRF-Token)
  N->>I: FastCGI (limit_req 20r/s burst 100, read_timeout 600s)
  I->>I: bootstrap (Config, Db UTC, Errorhandler)
  I->>R: dispatch(Request)
  R->>G: guard(route, request)
  G-->>R: 401/403 (HttpException) oder ok; Session::release()
  R->>C: handler(params)
  C-->>I: Response (JSON)
  I-->>B: JSON / Fehler-Mapping
```

### Worker-Zustandsmaschine

```mermaid
stateDiagram-v2
  direction LR
  state "Job" as J {
    CREATED --> RUNNING: Worker claimt ältesten Job
    RUNNING --> FAILED: discover() wirft
    RUNNING --> COMPLETED: keine offenen Dokumente (JobService::finalStatus)
    RUNNING --> FAILED: failed>0 und processed==0
    CREATED --> CANCELLED: API cancel
    RUNNING --> CANCELLED: API cancel (DISCOVERED-Dokumente → PENDING)
    CANCELLED --> RUNNING: API resume (started_at gesetzt)
    CANCELLED --> CREATED: API resume (nie gestartet)
    RUNNING --> CREATED: Worker-Neustart (recoverStaleWork)
  }
```

```mermaid
stateDiagram-v2
  direction LR
  [*] --> DISCOVERED: discover()
  [*] --> FAILED: discover(), Datei > MAX_DOCUMENT_SIZE
  DISCOVERED --> PROCESSING: Worker claimt
  PROCESSING --> COMPLETED
  PROCESSING --> FAILED: Exception (+ processing_errors)
  PROCESSING --> DISCOVERED: SIGTERM / Neustart
  DISCOVERED --> PENDING: Job CANCELLED
  PENDING --> DISCOVERED: Job resume / späterer Job (requeue)
  FAILED --> DISCOVERED: späterer Job (requeue) oder POST /documents/{id}/retry
```

`PAUSED` wurde entfernt (P6); Zustände: `CREATED/RUNNING/COMPLETED/FAILED/CANCELLED` ([app/src/Domain/JobStatus.php](app/src/Domain/JobStatus.php), `isResumable()` nur für `CANCELLED`).

## Core Components

| Komponente | Datei(en) | Verantwortung |
|---|---|---|
| Front Controller | [app/public/index.php](app/public/index.php) | Bootstrap, Router-Aufbau, Exception→HTTP-Mapping |
| Bootstrap | [app/config/bootstrap.php](app/config/bootstrap.php) | Autoloader, Error→Exception, `Config::load(getenv())`, `Db::connect` (`SET time_zone='+00:00'`), Zeitzone |
| Config | [app/src/Core/Config.php](app/src/Core/Config.php) | Typisierte Env-Zugriffe (`Config::string/int/…`), `secret()` (≥32 Zeichen, nicht `change-me…`) |
| Router | [app/src/Http/Router.php](app/src/Http/Router.php) | `{param}`→`[^/]+`, HEAD→GET, 405 mit `Allow`, Guard vor Handler, 400 bei kaputtem JSON |
| Routentabelle | [app/src/Controllers/Routes.php](app/src/Controllers/Routes.php) | **Alle** API-Routen, `public: true` markiert öffentliche |
| Security | [app/src/Security/](app/src/Security/) | AccessGuard, Auth (Argon2id), Session, Csrf, LoginThrottle, PathGuard |
| ProcessingService | [app/src/Services/ProcessingService.php](app/src/Services/ProcessingService.php) | Discovery, Dedupe/Versionierung, Blob-Speicherung, Convert→Chunk→Embed→Milvus |
| Chunker | [app/src/Services/Chunker.php](app/src/Services/Chunker.php) | Reine Funktion: Text → Chunks (Token-Schätzung, Overlap) |
| MilvusClient | [app/src/Services/MilvusClient.php](app/src/Services/MilvusClient.php) | REST v2, Collection-Schema, Insert/Delete/Search/Query, Retry bei Rate-Limit |
| EmbeddingClient / ConverterClient | [app/src/Services/EmbeddingClient.php](app/src/Services/EmbeddingClient.php), [app/src/Services/ConverterClient.php](app/src/Services/ConverterClient.php) | HTTP-Clients über [JsonClient](app/src/Http/JsonClient.php) |
| JobService | [app/src/Services/JobService.php](app/src/Services/JobService.php) | Job anlegen/listen/abbrechen, Zähler |
| DocumentService | [app/src/Services/DocumentService.php](app/src/Services/DocumentService.php) | Dokumente/Versionen/Chunks/Vektoren lesen, löschen, Download |
| SearchService | [app/src/Services/SearchService.php](app/src/Services/SearchService.php) | Semantische Suche (Query-Embedding + Milvus + MySQL-Hydration) |
| ExportService | [app/src/Services/ExportService.php](app/src/Services/ExportService.php) | tar.gz-Export/-Import inkl. Prüfsummen (≈740 Zeilen) |
| IntegrityService | [app/src/Services/IntegrityService.php](app/src/Services/IntegrityService.php) | Read-only Konsistenzprüfung MySQL ↔ Blobs ↔ Milvus |
| TlsService | [app/src/Services/TlsService.php](app/src/Services/TlsService.php) | Self-signed/CSR/Import/Aktivierung, Keys AES-256-GCM verschlüsselt |
| ModelService | [app/src/Services/ModelService.php](app/src/Services/ModelService.php) | Modellkatalog-Sync, Aktivierung |
| UploadService | [app/src/Services/UploadService.php](app/src/Services/UploadService.php) | Multipart-Upload nach `INPUT_ROOT` |
| UserService | [app/src/Services/UserService.php](app/src/Services/UserService.php) | Initial-Admin, Passwortrichtlinie, Passwortwechsel |
| Worker | [app/bin/worker.php](app/bin/worker.php) | Job-/Dokument-Schleife, Locks, Recovery, Finalisierung |
| Embedding-Service | [embedding/src/main.py](embedding/src/main.py) | Modell laden, `/embed`, `/embed/batch`, `/model`, `/model/active` |
| Converter-Service | [converter/src/main.py](converter/src/main.py) | `/convert` (Pfad-Allowlist), Formatweiche |
| SPA | [web/html/assets/js/app.js](web/html/assets/js/app.js) | `api()`-Wrapper (CSRF), Views per Hash-Route |

## Entry Points

| Einstieg | Datei | Ausgelöst durch | Hinweise |
|---|---|---|---|
| HTTP-API | [app/public/index.php](app/public/index.php) | nginx `location ^~ /api/` und `= /healthz` (FastCGI → `app:9000`) | Einziger Web-Einstieg; statische Dateien liefert nginx direkt |
| Worker | [app/bin/worker.php](app/bin/worker.php) | Container `worker` (Endlosschleife) | Advisory Lock `docvecwizard_worker`; weitere Instanzen warten (Standby) |
| Migrationen | [app/bin/migrate.php](app/bin/migrate.php) | Container `migrate` (one-shot) | Lock `docvec_migrate`; legt Initial-Admin an |
| Passwort setzen | [app/bin/set-password.php](app/bin/set-password.php) | manuell: `docker compose exec app php bin/set-password.php <user>` | Passwort per STDIN oder `DOCVEC_NEW_PASSWORD` |
| Healthcheck | [app/bin/healthcheck.php](app/bin/healthcheck.php) | Docker-Healthcheck `app`/`worker` | |
| Container-Init PHP | [app/docker-entrypoint.sh](app/docker-entrypoint.sh) | Start `app`/`worker`/`migrate` | Schreibt `zz-docvecwizard.ini` (Upload-Limits), legt Verzeichnisse an, CLI via `su-exec app` |
| Container-Init Web | [web/docker-entrypoint.sh](web/docker-entrypoint.sh) | Start `web` | Self-signed-Fallback aus `APP_HOSTNAMES`; pollt `cert.pem`-mtime alle 10 s → `nginx -s reload` |
| Embedding-API | [embedding/src/main.py](embedding/src/main.py) | uvicorn im Container `embedding` | |
| Converter-API | [converter/src/main.py](converter/src/main.py) | uvicorn im Container `converter` (tini) | |
| Modell-Download | [embedding/scripts/download-models.py](embedding/scripts/download-models.py) | manuell | Siehe [Open Questions](#open-questions) |
| SPA | [web/html/index.html](web/html/index.html) + [app.js](web/html/assets/js/app.js) | Browser | Init am Dateiende von app.js (`/api/auth/me`, dann Router) |
| Betriebsskripte | [scripts/](scripts/) | manuell (Host) | Backup DB/Milvus, Health, Status |
| Tests | [tests/unit/run.php](tests/unit/run.php), [tests/integration/run.sh](tests/integration/run.sh), [tests/smoke-test.sh](tests/smoke-test.sh) | manuell | Kein CI |

## Data Model

Quelle der Wahrheit: **[app/migrations/](app/migrations/)** (ausgeführt von `migrate.php`). [database/schema.sql](database/schema.sql) ist ein konsolidierter Endstand nur zur Referenz; [database/migrations/](database/migrations/) sind byteidentische Kopien – **werden nicht ausgeführt** [Fakt].

| Migration | Inhalt |
|---|---|
| `0001_initial.sql` | settings, embedding_models, jobs, documents (damals `document_id UNIQUE`), document_chunks, processing_errors, system_metrics, audit_log, tls_certificates, exports |
| `0002_*.sql` | Versionierung: entfernt UNIQUE auf `documents.document_id`, neu `document_version_id` (unique), `version`, `is_current`, `blob_id`, `metadata`; Tabelle `document_blobs`; `document_chunks.vector_id` |
| `0003_*.sql` | `users`, `login_attempts` |
| `0004_job_discovery_counters.sql` | `jobs.documents_skipped`, `jobs.documents_requeued` (`ADD COLUMN IF NOT EXISTS`) |

### Tabellen (Kernauszug)

| Tabelle | Schlüssel / Besonderheiten |
|---|---|
| `jobs` | `id` (BIGINT PK, intern) · `job_id` (UUID, API) · `name`, `source_directory`, `recursive`, `embedding_model`, `embedding_dimension` · `status` (CREATED/RUNNING/COMPLETED/FAILED/CANCELLED) · Zähler `documents_*` (inkl. `documents_skipped`, `documents_requeued` aus Migration 0004), `chunks_total`, `vectors_total`, `tokens_total`, `bytes_total`, `error_count` |
| `documents` | `id` (BIGINT PK, intern) · `document_id` (UUID, **logisches** Dokument über alle Versionen) · `document_version_id` (UUID, unique) · `version` · `is_current` · `job_id` (BIGINT FK → `jobs.id`, `ON DELETE SET NULL`) · `file_hash` (SHA-256, indiziert) · `source_path` (absoluter **Container**-Pfad) · `blob_id` (ohne FK) · `processing_status` · `embedding_model`/`embedding_dimension` · `metadata` (JSON) |
| `document_blobs` | `base64_data` LONGTEXT, unique `document_version_id`, `sha256` (des Originals) – **kein FK** zu documents; fehlt bei Versionen, die wegen `MAX_DOCUMENT_SIZE` als `FAILED` registriert wurden (`documents.blob_id IS NULL`) |
| `document_chunks` | `document_id` = **`documents.id` (BIGINT)**, nicht die UUID! (FK `ON DELETE CASCADE`) · `chunk_id` (UUID, unique) · `vector_id` = Milvus-Primärschlüssel · `chunk_index`, `page_start`, `page_end`, `text_length`, `token_count`, `text` |
| `processing_errors` | `job_id`, `document_id` (beide FK CASCADE), `step`, `error_message` |
| `embedding_models` | `name` unique, `dimension`, `distance_metric`, `active` (genau ein aktives Modell erwartet) |
| `users` | `username` unique, `password_hash` (Argon2id/bcrypt) |
| `login_attempts` | Throttling-Fenster pro User/IP |
| `audit_log` | `action`, `entity_type`, `entity_id`, `details` JSON (inkl. `actor` bei HTTP-Requests) |
| `tls_certificates` | `kind`, `cert_pem`, `csr_pem`, `private_key_enc` (**verschlüsselt**, AES-256-GCM), `active` |
| `exports` | `export_id` (UUID), `filename`, `status`, `manifest` JSON, `checksum`, `file_size` |
| `schema_migrations` | angewandte Migrationsdateien |
| `settings` | freier Schlüssel/Wert-Speicher (UI „Einstellungen“, `GET/PUT /api/settings`); **ohne Einfluss** auf die Verarbeitung (dokumentiert in CONFIGURATION.md) |
| `system_metrics` | Worker-Laufzeitmetriken (`document_duration_seconds` je Dokument, `job_*` je Auftrag), 30 Tage Aufbewahrung (`SystemService::pruneMetrics`), `GET /api/metrics` |

### ID-Semantik (häufige Fehlerquelle)

- v1 eines Dokuments: `document_id == document_version_id` [Fakt, ProcessingService].
- Neue Version: gleiche `document_id`, neue `document_version_id`, `version+1`, alte Version `is_current=0`.
- `document_chunks.document_id` → `documents.id` (numerisch); `documents.job_id` → `jobs.id` (numerisch, nicht `jobs.job_id`).
- Milvus-Entity (Schema in [MilvusClient](app/src/Services/MilvusClient.php)): `id` (VarChar(36) PK = `vector_id`), `document_id`, `document_version_id`, `chunk_id`, `job_id` (UUID), `source_path`, `filename`, `document_hash`, `embedding_model`, `embedding_dimension`, `chunk_index`, `page_start`, `page_end`, `vector` (FloatVector, `dim` = Modelldimension). **Kein Text** in Milvus – Text liegt nur in MySQL.
- Collection-Name: `docvec_<slug(modellname)>`, eine Collection pro Embedding-Modell, Metrik COSINE, Index AUTOINDEX.

```mermaid
erDiagram
  jobs ||--o{ documents : "job_id (SET NULL)"
  documents ||--o{ document_chunks : "documents.id (CASCADE)"
  documents ||--o{ processing_errors : "CASCADE"
  documents ||--o| document_blobs : "blob_id / document_version_id (kein FK)"
  document_chunks ||--|| MILVUS_ENTITY : "vector_id = id"
  embedding_models ||--o| MILVUS_COLLECTION : "docvec_<slug>"
```

## Data Flows

### 1. Ingest (Upload → Vektoren)

1. Upload (`POST /api/upload`, Felder `files[]` + `path`) → [UploadService](app/src/Services/UploadService.php) schreibt nach `INPUT_ROOT/<path>` (PathGuard, Namensvalidierung). **Erzeugt keinen Job.**
2. Job anlegen (`POST /api/jobs`, Body `name`, `source_directory`, `recursive`, `embedding_model`) → `jobs` mit `CREATED`.
3. Worker: claimt ältesten `CREATED`-Job → `RUNNING` → `ProcessingService::discover()` (liefert Statistik, schreibt `documents_skipped/requeued` + Audit `job.discover`):
   - `PathGuard::resolve(INPUT_ROOT, source)`; rekursiver Scan, Symlinks übersprungen, nur unterstützte Endungen (18, `SUPPORTED_EXTENSIONS`).
   - Pro Datei SHA-256 (gestreamt) + Größe; Entscheidung = reine Funktion `discoveryDecision()`:
     - gleicher Pfad + gleicher Hash, Version `COMPLETED/DISCOVERED/PROCESSING` → **skip_unchanged**
     - gleicher Pfad + gleicher Hash, Version `FAILED/PENDING` → **requeue** (Version wechselt zum neuen Job, Zähler des alten Jobs werden aktualisiert)
     - gleicher Pfad + anderer Hash → **new_version** (alte Version wird in **derselben** Transaktion `is_current=0`)
     - Pfad neu, Hash existiert **global** → **skip_duplicate** (E2: bleibt, aber sichtbar: Log mit vorhandenem Dokument, Zähler, UI-Hinweis)
     - sonst **new**
   - Datei > `MAX_DOCUMENT_SIZE` (100M): Version wird als `FAILED` mit deutscher Fehlermeldung registriert, kein Blob, `processing_errors` Schritt `discover`; bei späterer Erhöhung des Limits normales Requeue mit Nachspeichern des Blobs.
   - Original wird chunkweise Base64-kodiert (`Base64::encodeFile`) und in `document_blobs` gespeichert (Spitze ≈ 1,34 × Datei; `PHP_MEMORY_LIMIT` 384M).
4. Worker: claimt ein `DISCOVERED`/`PENDING`-Dokument eines `RUNNING`-Jobs → `processDocument()`:
   - Converter `POST /convert {path}` → `text`, `page_count`.
   - Seitenaufteilung per `\f` **nur für `.pdf`**, sonst Seite = `max(1, page_count)` (reine Funktion `chunkPages()`).
   - [Chunker](app/src/Services/Chunker.php) → Chunks.
   - Dimension aus `embedding_models` (`expectedDimension`), Collection sicherstellen; Altlasten (Chunks/Vektoren derselben Version) löschen (Idempotenz).
   - Embedding in Batches (`EMBED_BATCH_SIZE`) über `POST /embed/batch` **mit Modellnamen** → bei 409 (anderes Modell geladen) aktiviert der Worker das Job-Modell und wiederholt einmal; Dimension der Vektoren wird geprüft (`assertDimension`).
   - Chunks in MySQL speichern, dann Milvus-Insert (Retry bei Rate-Limit-Code 1807, exponentielles Backoff, 6 Versuche).
   - Status `COMPLETED` bzw. `FAILED` + `processing_errors`; Metrik `document_duration_seconds`.
5. Worker: Job ohne offene Dokumente → Zähler aktualisieren, **einmal** Milvus-Flush pro Job (Flush ist rate-limitiert), Status per `JobService::finalStatus()`, Job-Metriken, `pruneMetrics(30)`.

### 2. Suche

`POST /api/search` → [SearchService](app/src/Services/SearchService.php): aktives Modell aus `embedding_models` → Query-Embedding **mit Modellnamen** (409 vom Service → `HttpException::conflict`, Dimension geprüft) → Milvus-Search mit Kandidatenfenstern `candidateWindows(limit)` = `[min(250, max(5·limit, 20)), 1000]` → `filterCurrent()` behält nur Treffer, deren `document_version_id` `is_current=1` ist (E1) → Texte aus `document_chunks` nachladen. Vektoren alter Versionen bleiben in Milvus, werden aber nicht mehr ausgeliefert.

### 3. Export / Import

- Export (`POST /api/exports`, synchron im HTTP-Request, `set_time_limit(0)`): tar.gz via `PharData` unter `EXPORT_ROOT`:
  `documents/<versionId>/{metadata.json, original.b64, checksum.sha256, chunks.json, vectors.json}`, `mysql/*.json`, `milvus/collections.json`, `config/embedding-models.json`, `manifest.json` (`FORMAT_VERSION = '1.0'`), `checksums/SHA256SUMS`; anschließende Verifikation; Zeile in `exports`.
- Import (`POST /api/import`, Multipart-Feld `archive`, `strategy` = `skip|overwrite`): `assertSafeArchive` (max. Einträge/Bytes, Pfadregel `isSafeArchivePath`), Prüfsummen, Import je Version **mit Erhalt der IDs**, `job_id = NULL`, Jobs/Modelle werden nicht importiert. Milvus-Fehler nach MySQL-Commit → Exception, Teilzustand bleibt. Zusätzlich Legacy-Pfad für JSON-Manifest-Import. Smoke-Test prüft den Roundtrip (eigener Export → alles `reused`).

### 4. Löschen eines Dokuments

`DELETE /api/documents/{id}` → alle Versionen + Blobs in einer Transaktion (Chunks per CASCADE), danach **best effort** Milvus-Delete per `document_id`, Audit-Eintrag.

### 5. Login

`GET /api/csrf` (Token) → `POST /api/auth/login` (CSRF geprüft) → LoginThrottle → Argon2id-Verify (Dummy-Hash bei unbekanntem User) → Rehash falls nötig → `session_regenerate_id` → neues CSRF-Token in der Antwort.

### 6. TLS-Aktivierung

UI → `TlsService` (self-signed erzeugen / CSR / Zertifikat importieren) → `activate`: schreibt `key.pem` (0600) und danach atomar `cert.pem` nach `TLS_STORE` (`/srv/ssl` = `./web/ssl`) → `web`-Entrypoint erkennt mtime-Änderung (≤10 s) → nginx reload.

## Business Logic

### Dedupe und Versionierung (ProcessingService::discover)

- Identität einer Datei = **absoluter Container-Pfad** (`source_path`) + SHA-256; Entscheidungsregel: `ProcessingService::discoveryDecision()` (rein, getestet in `DiscoveryTest`).
- Unverändert + `COMPLETED/DISCOVERED/PROCESSING` → übersprungen. Unverändert + `FAILED/PENDING` → **requeue** in den neuen Job. Zusätzlich gezielter Retry: `POST /api/documents/{id}/retry` legt einen Ein-Dokument-Job „Erneut: <Datei>“ (`RUNNING`) an.
- Globaler Dedupe (E2): Inhalt existiert bereits unter anderem Pfad → übersprungen, **nicht** mit dem neuen Job verknüpft, aber geloggt/gezählt (`documents_skipped`, Audit `job.discover`, UI „N übersprungen“).
- Neue Version: alte Version wird `is_current=0` **in derselben Transaktion** wie Insert + Blob gesetzt (P5). Alte Chunks/Vektoren bleiben erhalten (Suche filtert sie heraus).
- Größenlimit `MAX_DOCUMENT_SIZE` (reine Regel `oversizeMessage()`): zu große Dateien werden als `FAILED` sichtbar registriert, nicht still verworfen.

### Job-Status und Finalisierung ([app/bin/worker.php](app/bin/worker.php))

Schleife pro Iteration (sequenziell, ein Dokument gleichzeitig):
1. ältesten `CREATED`-Job → `RUNNING`, `discover()` (Exception → Job `FAILED`; bereits entdeckte Dokumente bleiben `DISCOVERED` und werden nie verarbeitet [Abgeleitet]).
2. ein `DISCOVERED`/`PENDING`-Dokument eines `RUNNING`-Jobs verarbeiten.
3. `RUNNING`-Job ohne offene Dokumente finalisieren: Zähler aktualisieren, ein Milvus-Flush, Status per `JobService::finalStatus()` (`FAILED` nur wenn `failed > 0 && processed == 0`, sonst `COMPLETED`), Metriken schreiben.
4. Dokumente von `CANCELLED`-Jobs, die noch `DISCOVERED` sind → `PENDING` (+ Zähler). Wiederaufnahme per `POST /api/jobs/{id}/resume` (`PENDING` → `DISCOVERED`, Job → `RUNNING` bzw. `CREATED`, wenn nie gestartet) oder durch einen späteren Job (requeue).
5. Leerlauf → `sleep(WORKER_POLL_INTERVAL)`; alle ~30 Leerlauf-Ticks `ModelService::reconcileWithService()` (DB-Flag ist maßgeblich).

Start: Advisory Lock (Single Active Worker), Modellkatalog-Sync + Reconcile mit dem Embedding-Service, `recoverStaleWork` (`RUNNING`-Jobs → `CREATED`, `PROCESSING`-Dokumente → `DISCOVERED`). SIGTERM/SIGINT: laufendes Dokument → `DISCOVERED`, Lock freigeben.

### Chunking ([app/src/Services/Chunker.php](app/src/Services/Chunker.php))

Reine Funktion. Token-Schätzung ≈ `ceil(len/4)` pro Wort, CJK 1 Token/Zeichen. Absätze → Sätze → harte Splits; Overlap aus dem Ende des vorigen Chunks; harte Obergrenze `CHUNK_MAX_TOKENS`. Parameter: `CHUNK_SIZE_TOKENS` (512), `CHUNK_OVERLAP_TOKENS` (64), `CHUNK_MAX_TOKENS` (1024), `CHUNK_MIN_TOKENS` (50): ein kleinerer letzter Rest wird an den vorigen Chunk angehängt, sofern `CHUNK_MAX_TOKENS` eingehalten wird; `0` deaktiviert. Seitenweises Chunking: `ProcessingService::chunkPages()` (rein).

### Modelle

- Katalog: [embedding/catalog.json](embedding/catalog.json) (Qwen3-Embedding 0.6B/4B/8B mit unterschiedlichen Dimensionen). Worker und `POST /api/models/sync` übernehmen ihn in `embedding_models`. Gewichte werden vorab per `model-download` (Profil `tools`) bereitgestellt; der laufende Service hat kein Internet (E4).
- Aktivierung (`POST /api/models/activate`): zuerst Embedding-Service (`POST /model/active`, lädt Modell **in den Speicher**), dann DB-Flag `active`.
- **DB ist maßgeblich:** der Service startet mit `EMBEDDING_DEFAULT_MODEL`; der Worker gleicht ihn beim Start und im Leerlauf an `embedding_models.active` an (`reconcileWithService`).
- Verarbeitung und Suche senden den Modellnamen; der Service antwortet 409 bei Abweichung (`ModelMismatchException`) bzw. 503 ohne Modell. Worker: aktiviert Job-Modell und wiederholt; Suche: 409 an den Client. Dimensionen werden gegen `embedding_models.dimension` geprüft.

### Unterstützte Formate

Liste `SUPPORTED_EXTENSIONS` in [ProcessingService](app/src/Services/ProcessingService.php) (18 Endungen); Konvertierungsweiche in [converter/src/main.py](converter/src/main.py) (`PANDOC_FORMATS` → pandoc; Tabellen → LibreOffice headless → CSV; `.pptx/.odp/.ppt/.doc` → LibreOffice → PDF → pdftotext; PDF → `pdftotext -layout`; Fallback LibreOffice→PDF). Beide Listen müssen synchron bleiben.

## APIs and Integrations

### HTTP-API (alle unter `/api`, JSON)

Autoritative Liste: [app/src/Controllers/Routes.php](app/src/Controllers/Routes.php). [docs/API.md](docs/API.md) ist ergänzende Doku.

| Bereich | Routen | Controller |
|---|---|---|
| **öffentlich** | `GET /healthz`, `GET /api/auth/me`, `GET /api/csrf`, `POST /api/auth/login`, `POST /api/auth/logout` | System/Auth |
| Auth | `POST /api/auth/password` | AuthController |
| System | `GET /api/health`, `/system`, `/settings` (+`PUT`), `/integrity`, `/storage`, `/statistics`, `/statistics/extensions`, `/metrics`, `/collections`, `/collections/{name}/stats` | SystemController |
| Modelle | `GET /api/models`, `POST /api/models/activate`, `POST /api/models/sync` | ModelController |
| Jobs | `GET/POST /api/jobs`, `GET /api/jobs/{id}`, `POST /api/jobs/{id}/cancel`, `POST /api/jobs/{id}/resume` (nur `CANCELLED`) | JobController |
| Dokumente | `GET /api/documents`, `GET/DELETE /api/documents/{id}`, `POST /api/documents/{id}/retry` (202, nur `FAILED` + aktuell), `…/{id}/source|versions|chunks|vectors|download|metadata`, `GET /api/vectors/{id}`, `/api/vectors/{id}/document`, `/api/chunks/{id}`, `/api/chunks/{id}/source`, `/api/source/{id}` | DocumentController |
| Dateien | `GET /api/browse`, `POST /api/upload` | FileController |
| Export | `GET/POST /api/exports`, `GET /api/exports/{id}/download`, `POST /api/import` | ExportController |
| TLS | `GET /api/tls`, `POST /api/tls/csr`, `/selfsigned`, `/import`, `/{id}/activate` | TlsController |
| Suche | `POST /api/search` (`query`, `limit`) | SearchController |

### Interne Dienste (nur Netz `backend`, **ohne Authentifizierung**)

| Dienst | Endpunkte (genutzt) | Client |
|---|---|---|
| Embedding `embedding:8000` | `GET /health` (degraded ohne Modell), `GET /model` (Katalog + Status), `POST /model/active`, `POST /embed`, `POST /embed/batch` (optional `model`; 409 bei Abweichung, 503 ohne Modell) | [EmbeddingClient](app/src/Services/EmbeddingClient.php) |
| Converter `converter:8001` | `GET /health`, `POST /convert {path}` (nur unter `INPUT_ROOT`/`STAGING_ROOT`) | [ConverterClient](app/src/Services/ConverterClient.php) |
| Milvus `milvus:19530` | REST v2 `/v2/vectordb/...` (collections, entities insert/delete/query/search, flush); Health `milvus:9091/healthz` | [MilvusClient](app/src/Services/MilvusClient.php) |

HTTP-Transport: [JsonClient](app/src/Http/JsonClient.php) (`file_get_contents` + Stream-Context); Fehler → `UpstreamException` (trägt HTTP-Status). `MilvusClient::assertOk` behandelt HTTP 200 mit `code != 0` als Fehler.

## Configuration

Quelle: Umgebungsvariablen (`.env` → docker-compose → `getenv()`; PHP-FPM braucht dafür `clear_env = no` in [app/php-fpm.d/www.conf](app/php-fpm.d/www.conf)). Vorlage: [.env.example](.env.example). Doku: [docs/CONFIGURATION.md](docs/CONFIGURATION.md) (mit Code synchronisiert, P9).

| Variable | Verwendet in | Default (Code) | Hinweis |
|---|---|---|---|
| `MYSQL_HOST/DATABASE/USER/PASSWORD` | bootstrap.php | `db`/`docvec`/`docvec`/`''` | **Secret** (Passwort) |
| `MYSQL_ROOT_PASSWORD` | nur Container `db` | – | **Secret** |
| `SESSION_SECRET` | TlsService (Key-Verschlüsselung) | – (Pflicht ≥32 Zeichen, nicht `change-me…`) | **Secret**; Änderung macht gespeicherte TLS-Keys unlesbar |
| `ADMIN_USERNAME` / `ADMIN_PASSWORD` | UserService::ensureInitialAdmin (migrate) | `admin` / `''` | **Secret**; nur wirksam, wenn `users` leer ist |
| `SESSION_IDLE_TIMEOUT` / `SESSION_ABSOLUTE_TIMEOUT` | Session | 1800 / 43200 s | min. 60 s |
| `SESSION_SAVE_PATH` | Session | `/app/storage/sessions` | dokumentiert in CONFIGURATION.md |
| `LOGIN_MAX_ATTEMPTS_USER/IP`, `LOGIN_THROTTLE_WINDOW` | LoginThrottle | 5 / 20 / 900 s | |
| `INPUT_ROOT` | Processing, Job, Upload, Browse | `/srv/data/input` | Pfade werden absolut in DB gespeichert |
| `STAGING_ROOT` | Converter (Allowlist), SystemService (Anzeige) | `/srv/data/staging` | von PHP-Logik nicht genutzt |
| `EXPORT_ROOT` | ExportService | `/srv/data/exports` | in Compose gesetzt |
| `IMPORT_MAX_ENTRIES` / `IMPORT_MAX_BYTES` | ExportService | 200000 / 10 GiB | |
| `TLS_STORE` | TlsService | `/srv/ssl` | |
| `UPLOAD_MAX_SIZE` / `POST_MAX_SIZE` | UploadService, app-Entrypoint (PHP-ini) | `100M` / `200M` | nginx `client_max_body_size 200m` ([web/nginx.conf](web/nginx.conf)) muss ≥ sein |
| `MAX_DOCUMENT_SIZE` | ProcessingService::discover, JobService::retryDocument | `100M` | größere Quelldateien → Version `FAILED` ohne Blob; `0` deaktiviert |
| `PHP_MEMORY_LIMIT` | app-Entrypoint (PHP-ini, app + worker) | `384M` | Blob ≈ 1,34 × Datei; Container-`mem_limit` 512m |
| `CHUNK_SIZE_TOKENS/OVERLAP_TOKENS/MIN_TOKENS/MAX_TOKENS` | ProcessingService → Chunker | 512 / 64 / 50 / 1024 | |
| `EMBED_BATCH_SIZE` | ProcessingService | 32 (Compose/.env: 16) | |
| `WORKER_POLL_INTERVAL` | worker.php | 2 | in .env.example/Compose |
| `MILVUS_HOST/PORT/METRICS_PORT` | MilvusClient | `milvus`/19530/9091 | |
| `EMBEDDING_HOST/PORT` | EmbeddingClient | `embedding`/8000 | |
| `CONVERTER_HOST/PORT` | ConverterClient | `converter`/8001 | |
| `EMBEDDING_DEFAULT_MODEL` | embedding/src/main.py | – | beim Start geladenes Modell; danach gleicht der Worker an `embedding_models.active` an |
| `EMBEDDING_MODELS` | download-models.py (Service `model-download`, Profil `tools`) | `Qwen3-Embedding-0.6B` | vom laufenden Service selbst nicht genutzt |
| `HTTP_PROXY/HTTPS_PROXY/NO_PROXY` | nur `model-download` | – | Download außerhalb des internen Netzes (E4) |
| `MODELS_DIR` | Embedding-Service, Download-Skript | `/models` | |
| `CONVERT_TIMEOUT` | converter/src/main.py | 300 | |
| `APP_HOSTNAMES` | web-Entrypoint (Fallback-Zertifikat) | `localhost` | auch an `app` übergeben, dort ungenutzt |
| `HTTPS_PORT`, `DATA_DIR` | docker-compose.yml | – / `./data` | |
| `APP_NAME`, `APP_VERSION`, `APP_TIMEZONE`, `LOG_LEVEL` | diverse | `docvecwizard`, `0.0.0`, `UTC`, `INFO` | DB-Session ist immer UTC |
| `DOCVEC_NEW_PASSWORD` | set-password.php | – | optional statt STDIN |

Entfernt (P8, hatten nie Wirkung): `WORKER_CONCURRENCY`, `WORKER_BATCH_SIZE`, `WORKER_LOCK_TIMEOUT`.

Die Tabelle `settings` (`GET/PUT /api/settings`) wird nicht von der Verarbeitungslogik gelesen; das ist jetzt in UI und CONFIGURATION.md so dokumentiert [Fakt].

## Authentication and Authorization

- **Ein Benutzertyp, keine Rollen**: jeder angemeldete Benutzer darf alles [Fakt].
- Routen sind **standardmäßig privat**; nur `public: true` in Routes.php ist öffentlich. Neue Routen sind damit automatisch geschützt.
- [AccessGuard](app/src/Security/AccessGuard.php) (vom Router vor jedem Handler aufgerufen):
  - öffentlich + unsichere Methode → Session starten + CSRF prüfen;
  - privat → Session starten, `Auth::check()` sonst 401, CSRF bei unsicheren Methoden, danach **`Session::release()`** (Session-Lock frei, `$_SESSION` schreibgeschützt). Handler, die die Session ändern, müssen `Session::resume()` aufrufen (Beispiel: `AuthController::changePassword`).
- [Session](app/src/Security/Session.php): Name `docvec_sid`, strict mode, HttpOnly, `SameSite=Strict`, Secure bei HTTPS, Idle- und Absolut-Timeout.
- [Csrf](app/src/Security/Csrf.php): 64-Hex-Token in der Session, Header `X-CSRF-Token`, Rotation bei Login/Passwortwechsel; Antworten von me/csrf/login/logout/password enthalten `csrf_token`. Frontend `api()` speichert jedes `csrf_token` und wiederholt bei 403 einmal.
- [Auth](app/src/Security/Auth.php): Argon2id (bcrypt-Fallback), Dummy-Hash gegen Timing-User-Enumeration, Rehash beim Login, `session_regenerate_id`.
- [LoginThrottle](app/src/Security/LoginThrottle.php): Fehlversuche in `login_attempts`, Limits pro User und IP, 429 + `Retry-After`, opportunistische Bereinigung (>24 h).
- Passwortrichtlinie ([UserService](app/src/Services/UserService.php)): ≥12 Zeichen, ≠ Benutzername, nicht mit `change-me` beginnend.
- [PathGuard](app/src/Security/PathGuard.php): alle benutzerdefinierten Pfade relativ zu `INPUT_ROOT` auflösen (kein `..`, keine Symlink-Ausbrüche, Namensvalidierung).
- Interne Dienste (Embedding, Converter, Milvus, DB) haben **keine eigene Auth** – Schutz nur durch internes Docker-Netz.
- [Abgeleitet] Die SPA pollt `/api/health` alle 15 s; das erneuert das Idle-Timeout, solange ein Tab offen ist.

## Error Handling

Zentral in [app/public/index.php](app/public/index.php):

| Exception | HTTP | Body |
|---|---|---|
| `HttpException` | eigener Status (+ `Retry-After`) | eigene Nachricht |
| `InvalidArgumentException` | 400 | **Nachricht wird an den Client gegeben** (Services nutzen sie für deutsche Validierungstexte) |
| `UpstreamException` | 502 | generische deutsche Meldung, Details nur im Log |
| `Throwable` | 500 | generisch, Details nur im Log |

- PHP-Warnungen werden in bootstrap.php zu `ErrorException` → keine stillen Warnungen.
- Router: unbekannte Route 404, falsche Methode 405 (+`Allow`), ungültiges JSON 400.
- Worker: Fehler pro Dokument → `FAILED` + `processing_errors`, Worker läuft weiter; Fehler in `discover()` → Job `FAILED`.
- Milvus: Retry nur für Rate-Limit (Code 1807); sonst Exception.
- Python-Dienste: FastAPI-`HTTPException` (u. a. 400/403/404/409/415/422/503/504; z. B. 403 Pfad außerhalb der Allowlist, 422 Konvertierungsfehler, 504 Timeout) – im PHP-Client → `UpstreamException` → 502.
- **Regel**: Keine internen Details (Pfade, SQL, Stacktraces) in Responses – nur `InvalidArgumentException`/`HttpException`-Texte sind nutzersichtbar.

## Logging and Observability

- [Logger](app/src/Core/Logger.php)/[LoggerInstance](app/src/Core/LoggerInstance.php): JSON-Zeilen über `error_log` → stderr → `docker compose logs`. Level über `LOG_LEVEL`.
- [Audit](app/src/Services/Audit.php): fachliche Aktionen in `audit_log` (mit `actor` bei HTTP).
- Health: `/healthz` (öffentlich, minimal), `/api/health` (alle Dienste), Docker-Healthchecks pro Container, [scripts/check-health.sh](scripts/check-health.sh), [scripts/status.sh](scripts/status.sh).
- **Keine** Metriken/Tracing: `system_metrics` wird nie beschrieben, `/api/metrics` liefert daher leere Daten [Fakt].
- Integritätsprüfung: `GET /api/integrity` ([IntegrityService](app/src/Services/IntegrityService.php), read-only: fehlende Blobs, Base64-SHA-256, Milvus- vs. MySQL-Vektor-IDs, Dimensionen).

## Testing

| Ebene | Befehl | Voraussetzung |
|---|---|---|
| Unit (69 Tests) | `php tests/unit/run.php` | PHP 8.3 CLI lokal |
| Unit via Docker (verifiziert) | `docker run --rm -v "${PWD}:/w" -w /w php:8.3-cli-alpine php tests/unit/run.php` | Docker |
| Integration | `bash tests/integration/run.sh` | laufender Stack; kopiert [tests/fixtures/documents](tests/fixtures/documents) nach `data/input/fixtures` und führt `tests/integration/run.php` im `app`-Container aus |
| Smoke/E2E | `bash tests/smoke-test.sh` | laufender Stack, `.env` mit `ADMIN_PASSWORD`, `curl`, `jq`, `openssl`, `tar`; Browse-Check erwartet vorher geseedete Fixtures |

- Testframework: eigener Runner mit `test()`/`assert_*`-Helfern in [tests/unit/run.php](tests/unit/run.php); jede Datei `tests/unit/*Test.php` wird geladen. Stand: **105 Tests in 14 Dateien**.
- Abgedeckt (Unit): ArchivePath (`isSafeArchivePath`), Base64 (inkl. `encodeFile`), Chunker (inkl. `minTokens`), Config, Discovery (`discoveryDecision`, `oversizeMessage`), Embedding (`assertDimension`, `ModelMismatchException`), Hash, JobStatus (`isResumable`, `finalStatus`), Metadata, PageChunking (`chunkPages`), PathGuard, Search (`candidateWindows`, `filterCurrent`), Security (Router/Request/Response/Upload/TLS-Subject/Passwortrichtlinie), Uuid.
- **Nicht abgedeckt**: DB-gebundene Teile (ProcessingService::discover/processDocument, ExportService-Import, IntegrityService, Worker-Schleife, Controller), Frontend, Python-Dienste. Smoke-Test deckt Export → Import-Roundtrip ab.
- Welche Tests bei welcher Änderung: siehe [Change Map](#change-map).

## Build and Development

```bash
cp .env.example .env            # Secrets setzen: MYSQL_*, SESSION_SECRET (≥32 Zeichen), ADMIN_PASSWORD
docker compose build
docker compose up -d
docker compose ps
docker compose logs -f app worker
docker compose exec app php bin/set-password.php admin     # Passwort zurücksetzen
docker compose run --rm migrate                            # Migrationen erneut ausführen
```

- Kein Composer/npm-Build; Änderungen an `app/` oder `web/` erfordern `docker compose build <service>` (Code wird ins Image kopiert, kein Source-Mount) [Fakt: Dockerfiles `COPY`].
- Syntaxprüfung (auch in CI): `php -l <datei>`, `node --check web/html/assets/js/app.js`, `python -m py_compile embedding/src/*.py converter/src/*.py`, `docker compose config -q`.
- Zeilenenden: LF ist Pflicht (Shell-Entrypoints), erzwungen über [.gitattributes](.gitattributes).
- Lokales `php` auf Windows-Hosts kann fehlen/defekt sein → Docker-Variante der Unit-Tests nutzen.
- Weitere Doku: [docs/INSTALLATION.md](docs/INSTALLATION.md), [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md), [docs/ADMIN_GUIDE.md](docs/ADMIN_GUIDE.md).

## CI/CD

[.github/workflows/ci.yml](.github/workflows/ci.yml) (push + pull_request), ohne laufenden Stack: Job `php` (php:8.3-cli-alpine: `php -l` über `app/` und `tests/`, `php tests/unit/run.php`), `frontend` (`node --check app.js`), `python` (`py_compile` embedding/converter), `compose` (`docker compose config -q` mit `.env.example`, auch Profil `tools`; `diff -r app/migrations database/migrations`). Integrations-/Smoke-Tests laufen weiterhin manuell gegen einen Stack. Kein Release-Prozess, keine Pre-Commit-Hooks.

## Important Invariants

1. **Genau ein aktiver Worker** (Advisory Lock `docvecwizard_worker`). Verarbeitung ist strikt sequenziell; Logik verlässt sich darauf (z. B. Cancel-Sweep, Recovery).
2. `documents.document_version_id` ist global eindeutig; v1 hat `document_version_id == document_id`.
3. Pro `document_id` höchstens eine Version mit `is_current = 1` (nicht DB-erzwungen).
4. `document_chunks.vector_id` == Milvus-`id`; Chunks werden **vor** dem Milvus-Insert in MySQL geschrieben.
5. Eine Milvus-Collection pro Modell (`docvec_<slug>`); Vektordimension = `embedding_models.dimension`.
6. Alle Zeitstempel in der DB sind UTC (`SET time_zone='+00:00'`, `gmdate`); Frontend hängt `Z` an.
7. Benutzerpfade nur über `PathGuard` gegen `INPUT_ROOT`; `source_path` in der DB ist absolut (Container-Sicht).
8. UUIDs, die in Milvus-Filterstrings interpoliert werden, **müssen** vorher mit `Uuid::isValid` geprüft werden.
9. Routen sind privat, außer explizit `public: true`; unsichere Methoden brauchen CSRF.
10. Nach dem Guard ist die Session geschlossen – Schreibzugriffe nur nach `Session::resume()`.
11. Migrationen sind append-only, idempotent (`IF NOT EXISTS`) und ohne `;` in String-Literalen (naiver Splitter in migrate.php).
12. `app/migrations/` und `database/migrations/` (+ `database/schema.sql`) müssen manuell synchron gehalten werden.
13. Export-Format `FORMAT_VERSION = '1.0'`: Struktur ist Vertrag für Import/Roundtrip.
14. TLS-Keys sind mit `sha256(SESSION_SECRET)` verschlüsselt.

## Danger Zones

### DZ-1 Dokument-Pipeline & Versionierung
- **Ort**: [ProcessingService::discover/processDocument](app/src/Services/ProcessingService.php)
- **Warum riskant**: Verknüpft MySQL, Blobs, Converter, Embedding und Milvus ohne verteilte Transaktion; Dedupe-Regeln bestimmen, ob Dateien je (wieder) verarbeitet werden.
- **Was brechen kann**: Duplikate, verwaiste Vektoren, nie verarbeitete Dokumente, falsche `is_current`-Flags, Suche liefert alte Versionen.
- **Tests**: nur Unit (Chunker, Metadata); Pipeline nur via Integration/Smoke manuell → nach Änderung `tests/integration/run.sh` + `tests/smoke-test.sh` ausführen und `GET /api/integrity` prüfen.

### DZ-2 Worker-Zustandsmaschine
- **Ort**: [app/bin/worker.php](app/bin/worker.php), [JobService](app/src/Services/JobService.php), [JobStatus](app/src/Domain/JobStatus.php)
- **Warum riskant**: Status-Übergänge verteilt auf Worker, JobService und API; Annahme „ein Worker“.
- **Was brechen kann**: hängende Jobs (`RUNNING` ohne Fortschritt), doppelte Verarbeitung bei mehreren Workern ohne Lock, falsche Finalisierung.
- **Tests**: [tests/unit/JobStatusTest.php](tests/unit/JobStatusTest.php) (`isResumable`, `finalStatus`), [DiscoveryTest](tests/unit/DiscoveryTest.php). Manuell: Job anlegen, `docker compose restart worker` während Verarbeitung, Cancel → Resume testen.

### DZ-3 Embedding-Modell vs. Collection
- **Ort**: [ModelService](app/src/Services/ModelService.php), [EmbeddingClient](app/src/Services/EmbeddingClient.php), [embedding/src/main.py](embedding/src/main.py)
- **Warum riskant**: Aktives Modell existiert doppelt (Speicher des Embedding-Containers vs. `embedding_models.active`); DB ist maßgeblich, Abgleich durch den Worker (Start + Leerlauf). Verarbeitung/Suche senden den Modellnamen, Service antwortet 409 bei Abweichung.
- **Was brechen kann**: Während ein Job mit einem anderen Modell läuft, antwortet die Suche mit 409; nur ein Modell gleichzeitig im Speicher (Modellwechsel = Ladezeit).
- **Tests**: [tests/unit/EmbeddingTest.php](tests/unit/EmbeddingTest.php). Nach Änderung: Modell aktivieren, Embedding-Container neu starten, Suche + Job prüfen.

### DZ-4 Export/Import-Format
- **Ort**: [ExportService](app/src/Services/ExportService.php)
- **Warum riskant**: Archivformat ist Vertrag (Roundtrip, ältere Exporte); Import schreibt IDs 1:1 und läuft synchron im HTTP-Request; Archiv ist nicht vertrauenswürdige Eingabe.
- **Was brechen kann**: Unlesbare Altexporte, Teilimporte (MySQL committed, Milvus fehlgeschlagen), Path-Traversal/Zip-Bomb bei Lockerung von `assertSafeArchive`, Timeouts (nginx `fastcgi_read_timeout 600s`).
- **Tests**: [tests/unit/ArchivePathTest.php](tests/unit/ArchivePathTest.php) (Pfadregel); Smoke-Test Abschnitt 11 deckt Export **und** Import-Roundtrip (`skip`/`overwrite`) ab.

### DZ-5 Request-Sicherheit
- **Ort**: [Router](app/src/Http/Router.php), [AccessGuard](app/src/Security/AccessGuard.php), [Session](app/src/Security/Session.php), [Csrf](app/src/Security/Csrf.php), [Routes.php](app/src/Controllers/Routes.php)
- **Warum riskant**: Ein `public: true` zu viel oder ein Guard-Bypass öffnet die komplette API.
- **Was brechen kann**: Auth-Bypass, CSRF, Session-Fixation, verlorene Session-Writes (fehlendes `Session::resume`).
- **Tests**: [tests/unit/SecurityTest.php](tests/unit/SecurityTest.php) + Smoke-Test (Login, 401/403-Fälle).

### DZ-6 Pfadbehandlung
- **Ort**: [PathGuard](app/src/Security/PathGuard.php), [UploadService](app/src/Services/UploadService.php), [DirectoryBrowser](app/src/Services/DirectoryBrowser.php), Converter `resolve_input`/`_allowed_roots`
- **Warum riskant**: Benutzergesteuerte Pfade → Dateisystemzugriff.
- **Was brechen kann**: Path Traversal, Symlink-Ausbruch, Überschreiben fremder Dateien.
- **Tests**: [tests/unit/PathGuardTest.php](tests/unit/PathGuardTest.php), SecurityTest.

### DZ-7 Migrationen
- **Ort**: [app/bin/migrate.php](app/bin/migrate.php), [app/migrations/](app/migrations/)
- **Warum riskant**: Naiver SQL-Splitter, DDL nicht transaktional, Migration läuft automatisch bei jedem `up`.
- **Was brechen kann**: halb angewandte Migration, Start von `app`/`worker` blockiert (hängen von `migrate` completed ab).
- **Tests**: keine; gegen frische **und** bestehende DB testen (`docker compose down -v` nur in Testumgebung!).

### DZ-8 TLS-Verwaltung
- **Ort**: [TlsService](app/src/Services/TlsService.php), [web/docker-entrypoint.sh](web/docker-entrypoint.sh), [web/nginx.conf](web/nginx.conf)
- **Warum riskant**: Fehlerhaftes `cert.pem`/`key.pem` → nginx-Reload schlägt fehl/HTTPS nicht erreichbar; Secret-Rotation macht Keys unlesbar.
- **Was brechen kann**: Ausfall des einzigen Zugangs (Port 443).
- **Tests**: SecurityTest (Subject-Validierung); Aktivierung nur manuell.

### DZ-9 Speicher/Größenlimits
- **Ort**: Blob-Speicherung in ProcessingService (`Base64::encodeFile`), ExportService, `MAX_DOCUMENT_SIZE`, `PHP_MEMORY_LIMIT`, `UPLOAD_MAX_SIZE`/`POST_MAX_SIZE`, nginx `client_max_body_size`, MariaDB `max-allowed-packet=256M`, Container-`mem_limit`
- **Warum riskant**: Der Base64-Blob (+33 %) wird als ein Statement an MariaDB übergeben und liegt kurzzeitig komplett im PHP-Speicher.
- **Was brechen kann**: `MAX_DOCUMENT_SIZE` über ~190 MB scheitert am Packet-Limit; über ~280 MB am `PHP_MEMORY_LIMIT` (384M). Zu große Dateien werden seit P10 sichtbar als `FAILED` registriert statt den Worker zu beenden.
- **Tests**: DiscoveryTest (`oversizeMessage`), Base64Test (`encodeFile`).

## Change Map

| Änderung | Primäre Dateien | Ebenfalls prüfen | Tests |
|---|---|---|---|
| Neuer API-Endpunkt | `app/src/Controllers/Routes.php`, passender Controller | Service, `public`-Flag, `app.js` (`api()`), docs/API.md | SecurityTest (Router), Smoke |
| Neues Dateiformat | `ProcessingService::SUPPORTED_EXTENSIONS`, `converter/src/main.py` | Seiten-Split (`\f` nur PDF), UploadService (nutzt dieselbe Liste), docs/DOCUMENT_PROCESSING.md, Fixture | Integration, Smoke |
| Chunking ändern | `app/src/Services/Chunker.php`, `ProcessingService::chunkPages` | Env-Defaults, docs | ChunkerTest, PageChunkingTest |
| DB-Schema | neue `app/migrations/000N_*.sql` | Kopie nach `database/migrations/` (CI prüft Gleichheit), `database/schema.sql`, ExportService (mysql/*.json, Import-Spalten), docs/DATABASE.md | Integration, frische+bestehende DB |
| Milvus-Schema/Felder | `MilvusClient` (Schema, `OUTPUT_FIELDS`) | ProcessingService (Insert-Daten), ExportService (vectors.json), IntegrityService, bestehende Collections (keine Migration!) | MetadataTest, Integration |
| Embedding-Modell hinzufügen | `embedding/catalog.json` | Modelldateien via `docker compose --profile tools run --rm model-download`, `ModelService` Sync, Speicherlimits Compose | manuell |
| Auth/Session/CSRF | `app/src/Security/*` | `AuthController`, `app.js` (`api()`, Login), nginx Header | SecurityTest, Smoke |
| Export/Import-Format | `ExportService` | `FORMAT_VERSION`, Abwärtskompatibilität Import, docs/EXPORT_IMPORT.md | ArchivePathTest, Smoke (Export + Import-Roundtrip) |
| Worker-Logik/Status | `app/bin/worker.php`, `JobService`, `JobStatus`, `ProcessingService::discoveryDecision` | UI-Statuslabels `app.js`, docs/JOBS.md, docs/DOCUMENT_PROCESSING.md | JobStatusTest, DiscoveryTest, manuell (Restart/Cancel/Resume/Retry) |
| Neue Env-Variable | `Config::*`-Aufruf | `.env.example`, `docker-compose.yml` (pro Service!), docs/CONFIGURATION.md | ConfigTest, CI `compose` |
| Upload-/Dokumentlimits | `.env`, `app/docker-entrypoint.sh` (`PHP_MEMORY_LIMIT`), `MAX_DOCUMENT_SIZE` | `web/nginx.conf` `client_max_body_size`, MariaDB Packet | DiscoveryTest, Smoke |
| UI-View | `web/html/assets/js/app.js`, `index.html`, `app.css` | CSP (keine Inline-Skripte/-Styles, [web/security-headers.conf](web/security-headers.conf)), `esc()`/`enc()` | manuell im Browser |
| TLS | `TlsService`, `web/docker-entrypoint.sh` | `TLS_STORE`, Bind-Mount `./web/ssl` | SecurityTest |

## Where to Look First

| Aufgabe | Zuerst lesen |
|---|---|
| API-Fehler / 4xx/5xx | [app/public/index.php](app/public/index.php) → [Routes.php](app/src/Controllers/Routes.php) → Controller → Service; `docker compose logs app` |
| Job hängt / Dokument nicht verarbeitet | [app/bin/worker.php](app/bin/worker.php), [ProcessingService](app/src/Services/ProcessingService.php) (Dedupe-Regeln!), Tabelle `processing_errors`, `docker compose logs worker` |
| Suche liefert nichts/Falsches | [SearchService](app/src/Services/SearchService.php), aktives Modell (`embedding_models.active` vs. Embedding-Service `/model`), [MilvusClient](app/src/Services/MilvusClient.php) |
| Konvertierungsfehler | [converter/src/main.py](converter/src/main.py) |
| Login-/Session-Probleme | [AccessGuard](app/src/Security/AccessGuard.php), [Session](app/src/Security/Session.php), [Auth](app/src/Security/Auth.php), `api()` in [app.js](web/html/assets/js/app.js) |
| Export/Import | [ExportService](app/src/Services/ExportService.php), [docs/EXPORT_IMPORT.md](docs/EXPORT_IMPORT.md) |
| Inkonsistente Daten | `GET /api/integrity` → [IntegrityService](app/src/Services/IntegrityService.php) |
| Startprobleme der Container | [docker-compose.yml](docker-compose.yml) (depends_on/healthchecks), Entrypoints, [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) |
| HTTPS/Zertifikat | [TlsService](app/src/Services/TlsService.php), [web/docker-entrypoint.sh](web/docker-entrypoint.sh), [docs/HTTPS.md](docs/HTTPS.md) |
| Warum ist Code so? (Security) | [docs/AUDIT_REPORT.md](docs/AUDIT_REPORT.md), Kommentare `FIX:` / `SECURITY FIX:` |

## Technical Debt

Erledigt im Paket „Open Questions / Tech Debt“ (P1–P12, ein Commit je Paket, Branch `dareinelt-clarify-open-questions-improvements`): ehemals TD-1 (tote Variablen entfernt, `CHUNK_MIN_TOKENS` implementiert), TD-2 (`PAUSED` entfernt, Resume), TD-3 (Requeue + Retry), TD-4 (Metriken), TD-5 (`settings` dokumentiert), TD-6 (`activeModel()` nutzt `GET /model`), TD-7 (Suche nur `is_current`), TD-8 (atomares Umschalten), TD-12 (Zähler bei Cancel), TD-13 (CI, 105 Unit-Tests), TD-14 (Größenlimit, chunkweise Base64-Kodierung).

| # | Thema | Ort | Beleg |
|---|---|---|---|
| TD-9 | Export/Import synchron im HTTP-Request; Temp-Verzeichnisse bleiben bei Fehlern liegen [Abgeleitet] | ExportService | – |
| TD-10 | Duplizierte Migrationen (`app/migrations` ↔ `database/migrations`) + manuell gepflegtes `schema.sql` | database/ | byteidentische Kopien (CI prüft Gleichheit) |
| TD-11 | Naiver SQL-Splitter in migrate.php | app/bin/migrate.php | – |
| TD-15 | Viele `FIX:`/`SECURITY FIX:`-Kommentare (Audit-Historie) in ~17 Dateien | app/, web/, converter/ | grep `FIX:` |
| TD-16 | Vektoren/Chunks alter Versionen bleiben in Milvus/MySQL (Suche filtert sie nur heraus; Kandidatenfenster max. 1000) | ProcessingService, SearchService | – |
| TD-17 | Blob wird weiterhin als ein Statement geschrieben (Grenze `max_allowed_packet`); echtes Streaming in die DB fehlt | ProcessingService::storeBlob | – |
| TD-18 | DB-gebundene Pipeline-Teile (discover/processDocument/Import/Worker-Schleife) nur über Integration/Smoke gegen laufenden Stack getestet | tests/ | – |

Keine `TODO`/`FIXME`/`HACK`-Marker im Code vorhanden [Fakt, grep].

## Architectural Decisions

(Rekonstruiert aus Code, `.agent/job.md`, `.agent/ext1.md`, `docs/AUDIT_REPORT.md`; nicht formal als ADR dokumentiert.)

| Entscheidung | Begründung (belegt/abgeleitet) |
|---|---|
| Vanilla PHP ohne Framework/Composer | Vorgabe der Generierungs-Spezifikation; minimale Abhängigkeiten, offline-fähig [Abgeleitet] |
| Polling-Worker mit DB als Queue + Advisory Lock | Kein zusätzlicher Broker nötig; einfache Recovery |
| Milvus REST v2 statt SDK | Kein PHP-SDK nötig; reines HTTP |
| Originale als Base64 in MariaDB | Spezifikation [.agent/ext1.md](.agent/ext1.md): Self-contained DB + Export-Roundtrip |
| Eine Collection pro Modell | Unterschiedliche Dimensionen der Qwen3-Modelle |
| Ein Milvus-Flush pro Job | Flush ist in Milvus rate-limitiert (Kommentar im Worker) |
| Internes Docker-Netz für Backend-Dienste | Isolation; nur nginx exponiert |
| Session + CSRF statt Token-Auth, Single-Role | Audit-Refactor PR #4 ([docs/AUDIT_REPORT.md](docs/AUDIT_REPORT.md)) |
| TLS-Verwaltung in der App, Austausch über Bind-Mount + mtime-Polling | nginx-Container braucht keinen Zugriff auf DB/App |
| Strikte CSP, keine Inline-Skripte | Security-Härtung (security-headers.conf) |
| Suche liefert nur aktuelle Versionen (`is_current=1`), Filterung nach dem Milvus-Search mit Kandidatenfenstern `[min(250, max(5·limit, 20)), 1000]` | Entscheidung E1; alte Vektoren bleiben (kein Löschen beim Versionieren), Filter in PHP statt Milvus-Filterausdruck |
| Globaler Hash-Dedupe bleibt, Skips werden sichtbar gemacht (Log, Zähler, Audit, UI) | Entscheidung E2 |
| `PAUSED` entfernt, Resume nur aus `CANCELLED` (→ `RUNNING`, bzw. `CREATED` wenn nie gestartet) | Entscheidung E3 |
| Modellgewichte außerhalb des internen Netzes bereitstellen (`model-download`, Profil `tools`); laufender Embedding-Container ohne Internet | Entscheidung E4 |
| `embedding_models.active` ist maßgeblich; Worker gleicht Service an, Verarbeitung/Suche senden Modellnamen (409 bei Abweichung) | Verhindert stilles Verarbeiten mit falschem Modell nach Neustart |
| Unveränderte `FAILED`/`PENDING`-Versionen werden vom nächsten Job übernommen (requeue); gezielter Retry als Ein-Dokument-Job | Kein Löschen/Ändern von Dateien mehr nötig, Job-Zähler bleiben konsistent |
| Zu große Dateien (`MAX_DOCUMENT_SIZE`) werden als `FAILED` registriert statt verworfen | Sichtbarkeit; Limit begründet durch `max_allowed_packet`/`PHP_MEMORY_LIMIT` |
| Metriken 30 Tage, Bereinigung beim Job-Abschluss | Einfach, kein Cron nötig |

## Historical Context

- Repository-Historie kurz (2026-09-30 – 2026-10-01), vier PRs:
  1. **#1** Initialer 7-Service-Stack (generiert aus [.agent/job.md](.agent/job.md)).
  2. **#2** Doku / `docs/manual.pdf`.
  3. **#3** Base64-Blobs, Versionierung, Export-Roundtrip ([.agent/ext1.md](.agent/ext1.md)) → Migration 0002.
  4. **#4** Security-Audit-Refactor: Auth/Login (Migration 0003), CSRF, Session-Härtung, TLS-Härtung, Aufteilung des früheren ~600-Zeilen-`ApiController` in Bereichs-Controller, LF-Zeilenenden.
- `FIX:`-/`SECURITY FIX:`-Kommentare markieren Änderungen aus dem Audit; [docs/AUDIT_REPORT.md](docs/AUDIT_REPORT.md) beschreibt Befunde und Maßnahmen.
- [docs/DELIVERY_REPORT.md](docs/DELIVERY_REPORT.md) stammt aus der Generierungsphase und ist evtl. nicht mehr aktuell [Annahme].
- Branch `dareinelt-clarify-open-questions-improvements` (2026-10-01): Pakete P1–P12 lösten die früheren Open Questions 1–7 und TD-1…8/12/13/14 (siehe Architectural Decisions); Migration 0004 (`documents_skipped`, `documents_requeued`).

## Open Questions

Die ursprünglichen Fragen 1–7 (Modell-Download, Modellabgleich, `is_current`-Filter, globaler Dedupe, `PAUSED`/Resume, Doku-Abweichungen, Dateigrößen) sind entschieden und umgesetzt (E1–E4 vom Betreiber, Rest siehe Architectural Decisions). Offen bleibt:

1. Sollen Vektoren/Chunks alter Versionen beim Versionieren aus Milvus/MySQL entfernt werden (Speicher vs. Historie; vgl. TD-16)? Bis dahin gilt das Kandidatenfenster von max. 1000 Treffern.
2. Export/Import asynchron (Worker-Job) statt im HTTP-Request (TD-9)?
3. Soll ein Modellwechsel durch einen laufenden Job die Suche blockieren (aktuell 409 mit Hinweis) oder soll die Suche auf das Job-Ende warten/den Job pausieren?
4. Echtes Streaming der Blobs in die DB (TD-17), falls Dokumente > ~190 MB gebraucht werden.
5. Integrations-/Smoke-Tests der Pakete P1–P12 wurden **nicht** gegen einen laufenden Stack ausgeführt (nur Unit-Tests, Lint, Compose-Validierung) → vor Produktivsetzung manuell durchlaufen.

## Agent Operating Guidelines

1. **Code vor Doku**: `docs/*.md` wurde in P9 synchronisiert, kann aber wieder abdriften – Verhalten im Zweifel im Code verifizieren.
2. **Neue Routen** nur in [Routes.php](app/src/Controllers/Routes.php); `public: true` nur mit expliziter Begründung. Session-Writes im Handler → `Session::resume()`.
3. **Nutzerfehler** als `InvalidArgumentException`/`HttpException` mit deutscher Meldung; alles andere darf keine Details nach außen geben.
4. **SQL** nur mit Prepared Statements; UUIDs vor Milvus-Filterinterpolation mit `Uuid::isValid` prüfen; Pfade nur über `PathGuard`.
5. **Schemaänderung** = neue nummerierte Migration in `app/migrations/` (nie bestehende ändern), Kopie in `database/migrations/`, `database/schema.sql` nachziehen, ExportService/Import und IntegrityService prüfen. Keine `;` in String-Literalen.
6. **Milvus-Schemaänderungen** wirken nicht auf bestehende Collections – Migrationspfad explizit planen.
7. **Neue Env-Variablen** in `.env.example`, `docker-compose.yml` (jeder betroffene Service) und docs/CONFIGURATION.md eintragen. Niemals echte Secrets committen.
8. **Frontend**: keine Inline-Skripte/-Styles (CSP), alle dynamischen Werte über `esc()`/`enc()`, API-Aufrufe über `api()` (CSRF-Handling).
9. **Worker**: Annahme „ein aktiver Worker, sequenziell“ nicht brechen; Statusübergänge mit JobStatus-Enum und UI-Labels in app.js konsistent halten.
10. **Tests**: nach jeder PHP-Änderung Unit-Tests (Docker-Befehl oben, auch in CI); bei Pipeline/Export/Auth zusätzlich Integration + Smoke gegen laufenden Stack. Neue reine Logik mit Unit-Test in `tests/unit/*Test.php` absichern; DB-gebundene Logik möglichst in reine, statische Regeln auslagern (Muster: `discoveryDecision`, `finalStatus`, `filterCurrent`, `chunkPages`, `isSafeArchivePath`).
11. **LF-Zeilenenden** beibehalten (besonders `*.sh`).
12. **Destruktive Befehle** (`docker compose down -v`, Löschen von `data/`, `web/ssl/`, `embedding/models/`) nur nach Rückfrage – sie vernichten DB, Vektoren, Zertifikate bzw. Modelle.
13. Diese Datei bei architekturrelevanten Änderungen aktualisieren.

