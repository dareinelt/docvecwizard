# Tests

## Übersicht

Es gibt drei Testebenen:

| Ebene | Runner | Benötigt |
| --- | --- | --- |
| Unit-Tests | `tests/unit/run.php` | nur PHP |
| Integrationstests | `tests/integration/run.php` (via `run.sh`) | laufender Stack |
| Smoke-Test (E2E) | `tests/smoke-test.sh` | laufender Stack + jq/curl/openssl/tar |

## Unit-Tests

Testen isolierte Komponenten ohne externe Abhängigkeiten (reine Funktionen,
keine Datenbank, kein Netzwerk):

```bash
php tests/unit/run.php
# ohne lokales PHP:
docker run --rm -v "$PWD:/w" -w /w php:8.3-cli-alpine php tests/unit/run.php
```

Abgedeckte Klassen (aus `tests/unit/`):

| Testdatei | Testet |
| --- | --- |
| `Base64Test.php` | Base64-Kodierung der Original-Blobs |
| `ChunkerTest.php` | Text-Chunking, Überlappung, Token-Schätzung, `CHUNK_MIN_TOKENS`-Zusammenführung |
| `ConfigTest.php` | Konfigurations-Loader |
| `DiscoveryTest.php` | Discovery-Entscheidung (`ProcessingService::discoveryDecision`: neu / neue Version / Requeue / Skip) |
| `EmbeddingTest.php` | Dimensionsprüfung der Vektoren (`EmbeddingClient::assertDimension`), `ModelMismatchException` |
| `HashTest.php` | Hashing |
| `JobStatusTest.php` | Job-Zustände (kein `PAUSED`, Resume nur aus `CANCELLED`), Finalisierungsregel `JobService::finalStatus` |
| `MetadataTest.php` | Metadaten-Extraktion |
| `PathGuardTest.php` | Pfad-Auflösung/Path-Traversal-Schutz |
| `SearchTest.php` | Filterung der Suchtreffer auf aktuelle Versionen, Kandidatenfenster |
| `SecurityTest.php` | HTTP-Schicht: Upload-Normalisierung, Request-Parsing/-Validierung, Router (404/405, Guard, HEAD), sichere Download-/JSON-Header |
| `UuidTest.php` | UUID-Generierung |

## Integrationstests

Testen die Services gegen die laufende Infrastruktur (MySQL, Milvus,
Embedding, Converter):

```bash
tests/integration/run.sh
```

Das Skript:

1. stellt sicher, dass die Dienste gesund sind
2. führt `php /app/tests/integration/run.php` **im App-Container** aus

## Smoke-Test (End-to-End)

Vollständige, reproduzierbare Verifikation des Offline-Stacks:

```bash
tests/smoke-test.sh
```

Der Test deckt ab: Docker, Web, PHP, MySQL, Milvus, Embedding, Converter, API,
Dokumentenverarbeitung, Vektor-Insert, Statistik, Export, HTTPS.

Der Test:

- legt ein temporäres Smoke-Dokument unter `data/input/smoke/` an
- legt einen Job an und wartet auf dessen Abschluss
- prüft Health, Statistik, Suche und Export
- bleibt idempotent (Dedupe per Content-Hash)

## Testreport-Vorlage

Für den Abschlussbericht (siehe [manual.pdf](manual.pdf)):

```text
Test                 Erwartetes Ergebnis    Tatsächliches Ergebnis    Status
----                 -------------------    -----------------------    ------
...
```

## Ausführung im CI

Ohne laufenden Stack (so läuft es in `.github/workflows/ci.yml`):

```bash
# Syntax aller PHP-Dateien, Unit-Tests, JS-Syntax, Python-Compile,
# Compose-Validierung, Migrationen app/ == database/
docker run --rm -v "$PWD:/w" -w /w php:8.3-cli-alpine php tests/unit/run.php
node --check web/html/assets/js/app.js
python -m py_compile embedding/src/main.py converter/src/*.py
cp .env.example .env && docker compose config -q
diff -r app/migrations database/migrations
```

Mit laufendem Stack:

```bash
docker compose up -d --wait

# Integration
tests/integration/run.sh

# Smoke (inkl. Export/Import-Roundtrip)
tests/smoke-test.sh
```
