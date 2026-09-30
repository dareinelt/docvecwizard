# Tests

## Übersicht

Es gibt drei Testebenen:

| Ebene | Runner | Benötigt |
| --- | --- | --- |
| Unit-Tests | `tests/unit/run.php` | nur PHP |
| Integrationstests | `tests/integration/run.php` (via `run.sh`) | laufender Stack |
| Smoke-Test (E2E) | `tests/smoke-test.sh` | laufender Stack + jq/curl/openssl/tar |

## Unit-Tests

Testen isolierte Komponenten ohne externe Abhängigkeiten:

```bash
php tests/unit/run.php
```

Abgedeckte Klassen (aus `tests/unit/`):

| Testdatei | Testet |
| --- | --- |
| `ChunkerTest.php` | Text-Chunking, Überlappung, Token-Schätzung |
| `ConfigTest.php` | Konfigurations-Loader |
| `HashTest.php` | Hashing |
| `MetadataTest.php` | Metadaten-Extraktion |
| `PathGuardTest.php` | Pfad-Auflösung/Path-Traversal-Schutz |
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

```bash
# Voraussetzung: Stack läuft
docker compose up -d --wait

# Unit-Tests
php tests/unit/run.php

# Integration
tests/integration/run.sh

# Smoke
tests/smoke-test.sh
```
