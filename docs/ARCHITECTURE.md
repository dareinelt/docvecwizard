# Systemarchitektur

## Überblick

Der Document Embedding Manager ist eine vollständig containerisierte,
offlinefähige Webanwendung. Sie besteht aus acht Docker-Diensten, die über zwei
interne Netzwerke verbunden sind.

## Komponentendiagramm

```text
                        ┌──────────────────────────────┐
                        │           Browser            │
                        │      (HTTPS, Port 8443)      │
                        └──────────────┬───────────────┘
                                       │ HTTPS
                        ┌──────────────▼───────────────┐
                        │   web  (nginx TLS-Terminierung│
                        │        + statisches Frontend) │
                        └──────────────┬───────────────┘
                                       │ FastCGI
                        ┌──────────────▼───────────────┐
                        │    app  (PHP-FPM, REST-API)  │
                        └──┬───────┬───────┬───────┬───┘
                           │       │       │       │
                 ┌─────────▼┐ ┌────▼───┐ ┌─▼─────┐ ┌▼─────────┐
                 │   db     │ │ milvus │ │embed- │ │converter │
                 │ MariaDB  │ │ (Vektor│ │ding   │ │(Dokument │
                 │ 11.4     │ │ DB)    │ │(Qwen3)│ │Konverter)│
                 └─────────┘ └────────┘ └───────┘ └──────────┘
                                       ▲
                        ┌──────────────┴───────────────┐
                        │  worker  (PHP-CLI, Job-      │
                        │           Verarbeitung)      │
                        └──────────────────────────────┘
```

## Dienste im Detail

| Dienst | Image | Aufgabe | Port (intern) | Netzwerk |
| --- | --- | --- | --- | --- |
| `db` | mariadb:11.4 | Konfiguration, Jobs, Dokumente, Statistiken | 3306 | backend |
| `migrate` | app (build) | Einmalige Schema-Migration, beendet sich danach | – | backend |
| `milvus` | milvusdb/milvus:v2.5.27 | Vektordatenbank (standalone, embedded etcd) | 19530 / 9091 | backend |
| `embedding` | embedding (build) | Qwen3-Embeddings (Python/FastAPI) | 8000 | backend |
| `converter` | converter (build) | PDF/DOCX/PPTX/… → Text (Python/FastAPI) | 8001 | backend |
| `app` | app (build) | PHP-FPM REST-API | 9000 | frontend + backend |
| `worker` | app (build) | Asynchrone Job-Verarbeitung (PHP-CLI) | – | backend |
| `web` | web (build) | nginx TLS-Terminierung + statisches Frontend | 443 (→ 8443 extern) | frontend |

## Netzwerktopologie

```text
  extern ──► frontend (bridge) ──► web ──► app ──► backend (internal) ──► db
                          │                        │                    milvus
                          │                        │                    embedding
                          │                        │                    converter
                          │                        └─► worker ───────────┘
```

- **`frontend`** (bridge, öffentlich): nur `web` und `app` sind hier verbunden.
- **`backend`** (`internal: true`): kein Zugriff von außen möglich. Hier laufen
  `db`, `milvus`, `embedding`, `converter`, `app`, `worker`.

Nur der `web`-Dienst veröffentlicht einen Port (`8443` → `443`). Alle übrigen
Dienste sind ausschließlich intern erreichbar.

## Datenfluss (Verarbeitung)

```text
 1. Datei liegt in data/input/
 2. Nutzer erstellt Job → app schreibt Job in MariaDB
 3. worker entdeckt Dateien (Scan, SHA-256-Dedupe) → documents
 4. worker ruft converter auf → Text/Markdown
 5. worker zerlegt Text in Chunks (Chunker)
 6. worker ruft embedding auf → Vektoren
 7. worker schreibt Vektoren nach Milvus (Collection je Modell)
 8. worker aktualisiert Statistiken in MariaDB
 9. Suche: app → embedding (Query-Vektor) → milvus (Ähnlichkeitssuche)
```

## Technologie-Stack

| Schicht | Technologie |
| --- | --- |
| Backend | PHP 8.x (FPM), eigenes Routing, PDO |
| Frontend | Vanilla JS (kein Framework), HTML/CSS |
| Vektordatenbank | Milvus 2.5 (standalone) |
| Embedding | Qwen3-Embedding (0.6B/4B/8B) via PyTorch |
| Dokumentkonverter | Python + Pandoc + LibreOffice + Poppler |
| Datenbank | MariaDB 11.4 |
| Webserver | nginx (TLS-Terminierung) |
| Orchestrierung | Docker Compose |

## Verzeichnisstruktur

```text
project/
├── app/              # PHP-Backend (src/, bin/, migrations/, Dockerfile)
├── web/              # nginx-Konfiguration + statisches Frontend
├── embedding/        # Python Embedding-Service + catalog.json + models/
├── converter/        # Python Dokumentkonverter
├── database/         # schema.sql + migrations/ (Spiegel von app/migrations)
├── tests/            # unit/, integration/, smoke-test.sh
├── docs/             # diese Dokumentation
├── scripts/          # Hilfsskripte
├── data/             # Laufzeitdaten (input/, staging/, exports/) – nicht committed
├── docker-compose.yml
├── .env.example
├── README.md
└── LICENSE
```

## Ressourcenlimits

Der Stack ist bewusst konservativ dimensioniert, damit er auf einer
16-GB/8-CPU-Maschine läuft:

| Dienst | mem_limit | cpus |
| --- | --- | --- |
| db | 512m | – |
| migrate | 256m | – |
| milvus | 2g | – |
| embedding | 8g | 6 |
| converter | 2g | – |
| app | 512m | – |
| worker | 512m | – |
| web | 64m | – |

Siehe auch [PERFORMANCE](manual.pdf) und [CONFIGURATION.md](CONFIGURATION.md).
