# Konfiguration

Die gesamte Konfiguration erfolgt über die Datei `.env` im Projektstamm. Sie
wird von Docker Compose an die Container weitergereicht. Eine Vorlage liegt als
[`.env.example`](../.env.example) bei.

> **Wichtig:** Die `.env` enthält Secrets und ist deshalb in `.gitignore`
> ausgeschlossen. Niemals einchecken.

## Anwendung

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `APP_VERSION` | `0.1.0` | Angezeigte Versionsnummer |
| `APP_NAME` | `Document Embedding Manager` | Anzeigename |
| `APP_TIMEZONE` | `Europe/Berlin` | Zeitzone aller Dienste |
| `APP_HOSTNAMES` | `localhost` | Kommaseparierte FQDNs/IPs für TLS CN/SAN |

## Ports

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `HTTPS_PORT` | `8443` | Extern veröffentlichter HTTPS-Port (einziger öffentlicher Port) |

## MySQL / MariaDB

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `MYSQL_DATABASE` | `docvec` | Datenbankname |
| `MYSQL_USER` | `docvec` | Anwendungsbenutzer |
| `MYSQL_PASSWORD` | – | Passwort des Anwendungsbenutzers (**ändern!**) |
| `MYSQL_ROOT_PASSWORD` | – | Root-Passwort (**ändern!**) |

> Hinweis: Die MariaDB-`MARIADB_*`-Variablen werden nur bei der **ersten**
> Initialisierung des Datenvolumes gelesen. Nachträgliche Änderungen in `.env`
> wirken sich auf eine bestehende Datenbank nicht aus.

## Milvus

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `MILVUS_HOST` | `milvus` | Dienstname im Netzwerk |
| `MILVUS_PORT` | `19530` | gRPC/REST-Proxy-Port |
| `MILVUS_METRICS_PORT` | `9091` | Metrics-/Liveness-Port (`/healthz`) |

## Embedding-Service

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `EMBEDDING_HOST` | `embedding` | Dienstname |
| `EMBEDDING_PORT` | `8000` | API-Port |
| `EMBEDDING_MODELS` | `Qwen3-Embedding-0.6B` | Kommaseparierte Liste der beim Start geladenen Modelle |
| `EMBEDDING_DEFAULT_MODEL` | `Qwen3-Embedding-0.6B` | Standardmodell |

Verfügbare Modelle (aus `embedding/catalog.json`):

| Modell | Parameter | Dimension |
| --- | --- | --- |
| Qwen3-Embedding-0.6B | 0.6B | 1024 |
| Qwen3-Embedding-4B | 4B | 2560 |
| Qwen3-Embedding-8B | 8B | 4096 |

## Dokumentkonverter

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `CONVERTER_HOST` | `converter` | Dienstname |
| `CONVERTER_PORT` | `8001` | API-Port |
| `CONVERT_TIMEOUT` | `300` | Konvertierungs-Timeout in Sekunden |

## Datenverzeichnis

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `DATA_DIR` | `./data` | Host-Verzeichnis, das in die Container gemountet wird |
| `INPUT_ROOT` | `/srv/data/input` | Sandbox-Wurzel für Ordner-Browser & Scan (containerintern) |
| `STAGING_ROOT` | `/srv/data/staging` | Upload-/Konvertierungs-Staging (containerintern) |

Die Host-Pfade werden automatisch als Bind-Mounts gemappt:

```text
${DATA_DIR}/input    → /srv/data/input   (ro für converter)
${DATA_DIR}/staging  → /srv/data/staging
${DATA_DIR}/exports  → /srv/data/exports
```

## Limits

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `UPLOAD_MAX_SIZE` | `100M` | Maximale Größe je Upload-Datei (setzt auch PHP `upload_max_filesize`) |
| `POST_MAX_SIZE` | `200M` | Maximale Request-Größe (PHP `post_max_size`; nginx `client_max_body_size` = 200m) |
| `IMPORT_MAX_ENTRIES` | `200000` | Maximale Anzahl Einträge in einem Import-Archiv |
| `IMPORT_MAX_BYTES` | `10737418240` | Maximale entpackte Größe eines Import-Archivs (Bytes) |
| `CONVERT_TIMEOUT` | `300` | Timeout der Konvertierung |

## Chunking

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `CHUNK_SIZE_TOKENS` | `512` | Zielgröße eines Chunks (Tokens) |
| `CHUNK_OVERLAP_TOKENS` | `64` | Überlappung zwischen Chunks |
| `CHUNK_MIN_TOKENS` | `50` | Mindestgröße eines Chunks |
| `CHUNK_MAX_TOKENS` | `1024` | Harte Obergrenze eines Chunks |
| `EMBED_BATCH_SIZE` | `16` | Batch-Größe beim Embedding |

## Worker

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `WORKER_CONCURRENCY` | `2` | Gleichzeitig verarbeitete Dokumente |
| `WORKER_BATCH_SIZE` | `16` | Batch-Größe beim Dokument-Abruf |
| `WORKER_LOCK_TIMEOUT` | `1800` | Lock-Timeout in Sekunden (Crash Recovery) |

## Session / Sicherheit

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `SESSION_SECRET` | – | Geheimnis (min. 32 Zeichen) zur Verschlüsselung gespeicherter TLS-Schlüssel (**ändern!**, Platzhalter werden abgelehnt) |
| `SESSION_IDLE_TIMEOUT` | `1800` | Sitzungs-Leerlauf-Timeout in Sekunden |
| `SESSION_ABSOLUTE_TIMEOUT` | `43200` | Maximale Sitzungsdauer in Sekunden |
| `LOGIN_MAX_ATTEMPTS_USER` | `5` | Fehlversuche je Benutzer im Zeitfenster |
| `LOGIN_MAX_ATTEMPTS_IP` | `20` | Fehlversuche je IP im Zeitfenster |
| `LOGIN_THROTTLE_WINDOW` | `900` | Zeitfenster/Sperrdauer in Sekunden |
| `ADMIN_USERNAME` | `admin` | Initialer Administrator (nur `migrate`, nur wenn kein Konto existiert) |
| `ADMIN_PASSWORD` | – | Initiales Passwort (min. 12 Zeichen); nach dem ersten Start entfernen |

Secret erzeugen:

```bash
openssl rand -hex 32
```

Passwort setzen/zurücksetzen oder weiteres Konto anlegen:

```bash
docker compose exec app php bin/set-password.php admin
```

`CSRF_SECRET` wird nicht mehr verwendet (CSRF-Tokens sind zufällige
Session-Tokens).

## Logging

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `LOG_LEVEL` | `INFO` | Log-Level (`DEBUG`, `INFO`, `WARNING`, `ERROR`) |

## Änderungen anwenden

Nach jeder Änderung an `.env`:

```bash
docker compose up -d
```

Docker Compose erkennt geänderte Umgebungsvariablen und startet betroffene
Dienste neu.

## Laufzeit-Einstellungen (Datenbank)

Einige Einstellungen (z. B. aktives Embedding-Modell) werden nicht über `.env`,
sondern über die Datenbank-Tabelle `settings` bzw. die Oberfläche (Ansicht
„Einstellungen“) verwaltet. Siehe [ADMIN_GUIDE.md](ADMIN_GUIDE.md) und
[DATABASE.md](DATABASE.md).
