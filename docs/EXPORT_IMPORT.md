# Export & Import

## Übersicht

Das System kann **alle** indizierten Daten – Originaldokumente, Chunks,
Metadaten **und** Vektoren – als Archiv exportieren und ID-erhaltend wieder
importieren. Dies dient Backup, Migration und Austausch (auch in ein zweites
System).

## Export

### Ablauf

1. `POST /api/exports` legt einen Export mit `status=PENDING` an.
2. Der Export wird asynchron erstellt.
3. Das Ergebnis ist ein gzip-komprimiertes Archiv (`.tar.gz`) mit Manifest und
   SHA-256-Prüfsummen.
4. `GET /api/exports/{id}/download` lädt die Datei herunter.

### Inhalt

Das Exportarchiv enthält:

- **Manifest** (JSON): Zähler, Einbettungsmodelle, Collections, Checksummen
- **Originale** (`originals/`): byte-genaue Originaldateien als Base64
- **Chunk-Texte** (aus `document_chunks.text`)
- **Vektoren**: vollständige Float-Vektoren je Chunk
- **Beziehungen**: `document_version_id` ↔ `chunk_id` ↔ `vector_id`
- **Metadaten**: Seiten, Sprache, Autor, …
- **Prüfsummen** (SHA-256) je Datei und für das Gesamtarchiv

### Tabelle `exports`

| Spalte | Beschreibung |
| --- | --- |
| export_id | UUID |
| filename | Dateiname (`.tar.gz`) |
| format | `tar.gz` |
| status | PENDING/COMPLETED/FAILED |
| manifest | JSON-Manifest |
| checksum | SHA-256 |
| file_size | Größe |

### Status

| Status | Bedeutung |
| --- | --- |
| `PENDING` | angelegt, wird erstellt |
| `COMPLETED` | fertig, herunterladbar |
| `FAILED` | Fehler |

## Import

`POST /api/import` nimmt ein zuvor exportiertes Archiv (multipart `archive`
plus `strategy`) oder ein Legacy-Manifest (JSON) entgegen und stellt Dokumente,
Originale, Chunks und Vektoren wieder her.

Der Import verifiziert zuerst die Integrität (Manifest + SHA-256), bevor er
schreibt. Vektoren werden ID-erhaltend nach Milvus zurückgeschrieben.

### Konfliktstrategien

| Strategie | Verhalten |
| --- | --- |
| `skip` (Standard) | Vorhandene IDs überspringen |
| `overwrite` | Vorhandene IDs ersetzen |

### Ergebnis

Das Ergebnis meldet `imported`, `reused`, `skipped`, `overwritten` und
`conflicts`.

## Integritätsprüfung

`GET /api/integrity` prüft unabhängig vom Import den Gesamtzustand:
Base64-Roundtrips, Hashfehler, verwaiste Vektoren, fehlende Blobs/Chunks sowie
Collections- und Dimensionskonsistenz (siehe
[SOURCE_REFERENCE.md](SOURCE_REFERENCE.md)).

## Oberfläche

In der Ansicht **Export / Import**:

- **Export erstellen** – Export anlegen
- **Exportstatus** – Liste mit Status und Download-Link
- **Import** – `.tar.gz`-Archiv auswählen, Konfliktstrategie wählen und
  hochladen (oder Legacy-Manifest-JSON einfügen)

## Dateiablage

Exporte liegen im Container-/Host-Verzeichnis:

```text
data/exports/
```

(`EXPORT_ROOT=/srv/data/exports`, gemappt auf `${DATA_DIR}/exports`).

## Hinweise

- Exporte bleiben bis zur manuellen Löschung erhalten.
- Die **Originaldokumente** sind Teil des Exports (Base64 im Archiv) und können
  beim Import vollständig wiederhergestellt werden.
- Der Import erfordert passende Embedding-Dimensionen/Collections
  (Dimensionsprüfung vorhanden).
