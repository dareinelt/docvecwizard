# Installation

## Voraussetzungen

| Komponente | Version | Hinweis |
| --- | --- | --- |
| Docker Engine | ≥ 24 | inkl. Compose v2 |
| RAM | ≥ 16 GB | 8 GB entfallen auf den Embedding-Dienst |
| CPU-Kerne | ≥ 8 | 6 Kerne sind dem Embedding-Dienst zugewiesen |
| Festplatte | ≥ 20 GB | Modell-Download + Docker-Images + Daten |
| Internet | einmalig | nur für Image-Build und Modell-Download |

> **Hinweis:** Für den reinen **Offline-Betrieb** (siehe
> [OFFLINE_OPERATION.md](OFFLINE_OPERATION.md)) ist Internet nur beim ersten
> Aufsetzen erforderlich.

## Schritt 1: Repository bereitstellen

```bash
git clone <repository-url> docvecwizard
cd docvecwizard
```

## Schritt 2: Konfiguration anlegen

```bash
cp .env.example .env
```

Die `.env`-Datei **muss** vor dem ersten Start angepasst werden. Zwingend zu
ändern sind mindestens:

```dotenv
MYSQL_PASSWORD=…
MYSQL_ROOT_PASSWORD=…
SESSION_SECRET=…
ADMIN_PASSWORD=…
```

Die Secrets müssen lang und zufällig sein. Erzeugen mit:

```bash
openssl rand -hex 32
```

Alle übrigen Optionen sind in [CONFIGURATION.md](CONFIGURATION.md) dokumentiert.

## Schritt 3: Images bauen

```bash
docker compose build
```

**Zweck:** Baut die vier eigenen Images (`app`, `web`, `embedding`, `converter`)
sowie die Basis-Images (`mariadb`, `milvus`). `app` wird zusätzlich für
`worker` und `migrate` verwendet.

**Erwartetes Ergebnis:** Alle Images werden ohne Fehler gebaut.

**Typische Fehler:**

- *Netzwerkfehler beim Pull der Basis-Images* → Internetverbindung prüfen,
  ggf. Docker-Daemon neu starten.
- *Build-Kontext nicht gefunden* → sicherstellen, dass `app/`, `embedding/`,
  `converter/` und `web/` vorhanden sind.

## Schritt 4: Embedding-Modelle herunterladen

Der laufende `embedding`-Dienst hängt ausschließlich am internen Docker-Netz
(ohne Internet) und lädt **nur bereits vorhandene** Modelle aus
`./embedding/models/<Modellname>/`. Die Modelle müssen daher **vor dem ersten
Start** einmalig bereitgestellt werden:

```bash
docker compose --profile tools run --rm model-download
```

**Zweck:** Startet einen Einmal-Container (gleiches Image wie `embedding`, aber
im Netz `tools` mit Internetzugang), der alle in `EMBEDDING_MODELS`
aufgeführten Modelle von Hugging Face nach `./embedding/models` lädt
(`embedding/scripts/download-models.py`). Bereits vorhandene Modelle werden
übersprungen; der Aufruf ist wiederholbar.

**Erwartetes Ergebnis:** Für jedes Modell `DONE <Name>`; danach existiert
`embedding/models/<Name>/config.json` sowie mindestens eine `*.safetensors`-Datei.
Das Standardmodell `Qwen3-Embedding-0.6B` hat ca. 1,2 GB, `4B`/`8B` entsprechend
mehr – siehe [EMBEDDING.md](EMBEDDING.md).

**Alternative ohne Docker-Internetzugang:** Modelle auf einem anderen Rechner
laden (`pip install huggingface_hub`, dann
`MODELS_DIR=./embedding/models python embedding/scripts/download-models.py`)
und das Verzeichnis `embedding/models/` auf den Zielhost kopieren
(siehe [OFFLINE_OPERATION.md](OFFLINE_OPERATION.md)). Hinter einem Proxy
`HTTP_PROXY`/`HTTPS_PROXY` in `.env` setzen; sie werden an `model-download`
durchgereicht.

## Schritt 5: Stack starten

```bash
docker compose up -d
```

**Zweck:** Startet alle acht Dienste. Die Reihenfolge ist über `depends_on`
und `healthcheck` abgesichert:

1. `db` (MariaDB) startet und wird gesund
2. `migrate` führt die Datenbank-Migration aus und beendet sich
3. `milvus`, `embedding`, `converter` starten parallel
4. `app` startet, sobald `db` gesund und `migrate` abgeschlossen ist
5. `worker` startet, sobald zusätzlich `embedding` gesund ist
6. `web` startet, sobald `app` gesund ist

**Erwartetes Ergebnis:** `docker compose ps` zeigt alle Dienste mit Status
`running` (bzw. `exited (0)` für `migrate`, das ist korrekt).

Fehlt das Standardmodell, startet `embedding` trotzdem, meldet aber im Log
`model … is not present in /models/…` und über `/health`
`"model_loaded": false` mit dem Fehlertext. `GET /api/health` zeigt dann
`"embedding": false`.

## Schritt 6: Status verifizieren

```bash
docker compose ps
```

**Erwartetes Ergebnis:** `web`, `app`, `worker`, `embedding`, `converter`,
`milvus`, `db` laufen; `migrate` ist `exited (0)`.

Health-Check des Gesamtsystems:

```bash
curl -sk https://localhost:8443/api/health
```

**Erwartetes Ergebnis:**

```json
{"status":"ok","checks":{"database":true,"embedding":true,"converter":true,"milvus":true}}
```

## Schritt 7: Oberfläche öffnen

Öffnen Sie **https://localhost:8443** im Browser. Wegen des selbstsignierten
Zertifikats erscheint eine Warnung – diese einmalig bestätigen („Erweitert“ →
„Trotzdem fortfahren“). Details in [HTTPS.md](HTTPS.md).

## Schritt 8: Testdaten anlegen

Ein Verzeichnis unter `data/input/` anlegen und Beispieldokumente hineinlegen:

```bash
mkdir -p data/input/beispiele
echo "Der schnelle braune Fuchs springt über den faulen Hund." > data/input/beispiele/text.txt
```

Anschließend in der Oberfläche einen Auftrag auf das Verzeichnis `beispiele`
starten (siehe [USER_GUIDE.md](USER_GUIDE.md)).

## Deinstallation / Reset

```bash
# Nur Dienste stoppen (Daten bleiben erhalten)
docker compose down

# Dienste stoppen UND Datenvolumes löschen (Achtung: unwiderruflich!)
docker compose down -v
```

## Nächste Schritte

- [CONFIGURATION.md](CONFIGURATION.md) – alle Optionen im Detail
- [ADMIN_GUIDE.md](ADMIN_GUIDE.md) – Betrieb und Wartung
- [OFFLINE_OPERATION.md](OFFLINE_OPERATION.md) – vollständig offline betreiben
