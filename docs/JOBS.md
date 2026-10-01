# Job-System

## Übersicht

Ein **Job** (Auftrag) repräsentiert die Indizierung eines Quellverzeichnisses.
Der **Worker** verarbeitet Jobs asynchron.

## Job-Status

Aus `JobService`:

| Status | Bedeutung |
| --- | --- |
| `CREATED` | angelegt, wartet auf Verarbeitung |
| `RUNNING` | wird verarbeitet |
| `COMPLETED` | abgeschlossen (auch bei einzelnen fehlgeschlagenen Dokumenten, sofern mindestens eines erfolgreich war) |
| `FAILED` | fehlgeschlagen (Discovery-Fehler oder kein einziges Dokument erfolgreich) |
| `CANCELLED` | abgebrochen; kann fortgesetzt werden |

Einen Status `PAUSED` gibt es nicht (wurde nie gesetzt und ist entfernt).

## Lebenszyklus

```text
CREATED ──► RUNNING ──► COMPLETED
   ▲          │  └──► FAILED
   │          └──► CANCELLED ──► RUNNING (resume)
   └──────────────────┘ (resume, falls noch nie gestartet)
```

1. **Anlegen:** `POST /api/jobs` prüft das Embedding-Modell und legt den Job
   mit `status=CREATED` an.
2. **Claim:** Der Worker übernimmt `CREATED`-Jobs atomar (`CREATED → RUNNING`).
3. **Scan:** Der Worker scannt das Quellverzeichnis rekursiv (oder nicht),
   dedupliziert per SHA-256 und legt `documents` mit `DISCOVERED` an.
   Unveränderte Dateien, deren aktuelle Version `FAILED` oder `PENDING` ist,
   werden dem neuen Job zugeordnet und erneut verarbeitet.
4. **Verarbeitung:** Dokumente werden nacheinander konvertiert, gechunkt,
   eingebettet und nach Milvus geschrieben.
5. **Abschluss:** `finalizeJob()` berechnet die aggregierten Zähler und setzt
   den Status: `FAILED` nur, wenn kein Dokument erfolgreich war
   (`JobService::finalStatus()`), sonst `COMPLETED`.

## Worker

`app/bin/worker.php` – ein PHP-CLI-Prozess mit Schleife:

- **Claim** von Jobs (`CREATED → RUNNING`) und Dokumenten
  (`DISCOVERED → PROCESSING`) über atomare SQL-Updates.
- **Batch-Verarbeitung** (`WORKER_BATCH_SIZE`).
- **Concurrency** über `WORKER_CONCURRENCY`.

### Graceful Shutdown

Der Worker behandelt `SIGTERM`/`SIGINT` (über `pcntl_async_signals`): Der
laufende Verarbeitungsschritt wird beendet, dann der Prozess sauber gestoppt.

### Crash Recovery

Beim Start führt der Worker `recoverStaleWork()` aus:

- `RUNNING`-Jobs → `CREATED` (neu beanspruchbar)
- `PROCESSING`-Dokumente → `DISCOVERED` (erneut verarbeitbar)

Dadurch bleiben nach einem harten Absturz (OOM, Kill) keine hängenden Einträge
zurück.

## Konfiguration

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `WORKER_CONCURRENCY` | 2 | parallele Dokumente |
| `WORKER_BATCH_SIZE` | 16 | Dokumente je Batch |
| `WORKER_LOCK_TIMEOUT` | 1800 | Lock-Timeout (s) |
| `WORKER_POLL_INTERVAL` | 2 | Poll-Intervall (s) |

## Abbruch und Fortsetzung

`POST /api/jobs/{id}/cancel` setzt einen nicht-terminalen Job auf `CANCELLED`.
Noch nicht verarbeitete Dokumente des Jobs werden auf `PENDING` geparkt, die
Zähler werden aktualisiert. Terminale Jobs (`COMPLETED`, `FAILED`,
`CANCELLED`) können nicht abgebrochen werden.

`POST /api/jobs/{id}/resume` setzt einen `CANCELLED`-Job fort: geparkte
`PENDING`-Dokumente werden wieder `DISCOVERED`, der Job geht nach `RUNNING`
(bzw. nach `CREATED`, falls er vor seiner Discovery abgebrochen wurde) und
`finished_at` wird gelöscht. `COMPLETED`/`FAILED` sind endgültig; einzelne
fehlgeschlagene Dokumente lassen sich über `POST /api/documents/{id}/retry`
oder einen neuen Job über dasselbe Verzeichnis erneut verarbeiten.

## Statistik

Pro Job werden geführt:

- `documents_total`, `documents_pending`, `documents_processing`,
  `documents_processed`, `documents_failed`
- `documents_skipped`, `documents_requeued` (Discovery-Ergebnis, siehe
  [DOCUMENT_PROCESSING.md](DOCUMENT_PROCESSING.md#deduplizierung))
- `chunks_total`, `vectors_total`
- `tokens_total`, `bytes_total`
- `error_count`

## Beobachtung

- Oberfläche: Ansicht **Aufträge**
- Logs: `docker compose logs -f worker`
