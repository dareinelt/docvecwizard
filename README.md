# Document Embedding Manager

Eine vollständig offlinefähige Webanwendung zum Indizieren von Dokumenten und zur
semantischen Suche über lokale Embeddings. Die Anwendung liest Dokumente aus einem
lokalen Ordner, wandelt sie in Text um, zerlegt sie in überlappende Chunks, erzeugt
mit **Qwen3**-Embedding-Modellen Vektoren und speichert diese in **Milvus**. Die
Weiterverarbeitung erfolgt über einen eigenständigen Worker-Prozess.

Alle Komponenten laufen in Docker und benötigen zur Laufzeit **keine**
Internetverbindung.

## Architektur im Überblick

```text
Browser
  ↓ HTTPS (8443)
Web (nginx, TLS-Terminierung, statisches Frontend)
  ↓ FastCGI
App (PHP-FPM, REST-API)
  ├── MySQL/MariaDB   (Konfiguration, Jobs, Dokumente, Statistiken)
  ├── Milvus          (Vektordatenbank)
  ├── Embedding       (Qwen3-Embedding-Service, Python)
  └── Converter       (PDF/DOCX/PPTX/XLSX/… → Text, Python)
        ↑
Worker (PHP-CLI, verarbeitet Jobs asynchron)
```

Acht Dienste bilden den Stack: `db`, `migrate`, `milvus`, `embedding`,
`converter`, `app`, `worker` und `web`. Nur `web` veröffentlicht einen Port
(Standard: `8443` → `443`). Alle übrigen Dienste sind ausschließlich über interne
Docker-Netzwerke erreichbar.

## Schnellstart

```bash
# 1. Konfiguration anlegen
cp .env.example .env
#    .env editieren – insbesondere Passwörter und Secrets ändern
#    (SESSION_SECRET: openssl rand -hex 32) und ADMIN_PASSWORD setzen

# 2. Images bauen
docker compose build

# 3. Embedding-Modelle einmalig herunterladen (benötigt Internet, nach ./embedding/models)
docker compose --profile tools run --rm model-download

# 4. Stack starten (migriert die Datenbank)
docker compose up -d

# 5. Status prüfen
docker compose ps

# 6. Logs beobachten
docker compose logs -f app worker
```

Die Oberfläche ist anschließend unter **https://localhost:8443** erreichbar
(selbstsigniertes Zertifikat, daher Browser-Warnung bestätigen). Anmeldung mit
`ADMIN_USERNAME`/`ADMIN_PASSWORD` aus `.env`; das Passwort kann danach in der
Oberfläche unter „Einstellungen“ geändert und `ADMIN_PASSWORD` aus `.env`
entfernt werden. Ohne gesetztes `ADMIN_PASSWORD` ein Konto anlegen mit
`docker compose exec app php bin/set-password.php admin`.

## Voraussetzungen

- Docker (Engine ≥ 24) und Docker Compose (v2)
- mind. 16 GB RAM und 8 CPU-Kerne empfohlen (Stack ist bewusst konservativ dimensioniert)
- die Embedding-Modelle werden **nicht** automatisch geladen: der laufende `embedding`-Container hat keinen Internetzugang. Einmalig `docker compose --profile tools run --rm model-download` ausführen (siehe [docs/EMBEDDING.md](docs/EMBEDDING.md)); danach ist der Betrieb vollständig offline möglich

## Dokumentation

Die ausführliche Dokumentation liegt im Verzeichnis [`docs/`](docs/README.md):

| Dokument | Inhalt |
| --- | --- |
| [INSTALLATION.md](docs/INSTALLATION.md) | Schritt-für-Schritt-Installation |
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | Systemarchitektur und Diagramme |
| [CONFIGURATION.md](docs/CONFIGURATION.md) | Alle Konfigurationsoptionen |
| [USER_GUIDE.md](docs/USER_GUIDE.md) | Benutzerhandbuch |
| [ADMIN_GUIDE.md](docs/ADMIN_GUIDE.md) | Administratorhandbuch |
| [TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Fehlerbehebung |
| [SECURITY.md](docs/SECURITY.md) | Sicherheitskonzept |
| [API.md](docs/API.md) | REST-API-Referenz |
| [DATABASE.md](docs/DATABASE.md) | Datenbankschema |
| [MILVUS.md](docs/MILVUS.md) | Vektordatenbank |
| [EMBEDDING.md](docs/EMBEDDING.md) | Embedding-Service |
| [DOCUMENT_PROCESSING.md](docs/DOCUMENT_PROCESSING.md) | Dokumentverarbeitung |
| [JOBS.md](docs/JOBS.md) | Job-System |
| [EXPORT_IMPORT.md](docs/EXPORT_IMPORT.md) | Export & Import |
| [HTTPS.md](docs/HTTPS.md) | TLS/Zertifikate |
| [TESTING.md](docs/TESTING.md) | Tests |
| [OFFLINE_OPERATION.md](docs/OFFLINE_OPERATION.md) | Offline-Betrieb |
| [manual.pdf](docs/manual.pdf) | DIN-A4-Gesamtdokumentation |

## Tests

```bash
# Unit-Tests (PHP, ohne Abhängigkeiten)
php tests/unit/run.php

# Integrationstests (benötigt laufenden Stack)
php tests/integration/run.php

# End-to-End-Smoke-Test (benötigt jq, curl, openssl, tar)
tests/smoke-test.sh
```

Siehe [docs/TESTING.md](docs/TESTING.md) für Details.

## Lizenz

Siehe [LICENSE](LICENSE).
