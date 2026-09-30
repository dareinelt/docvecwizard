# Export & Import

## Übersicht

Das System kann alle indizierten Daten (Dokumente, Chunks, Metadaten) als
Archiv exportieren und wieder importieren. Dies dient Backup, Migration und
Austausch.

## Export

### Ablauf

1. `POST /api/exports` legt einen Export mit `status=PENDING` an.
2. Der Export wird asynchron erstellt.
3. Das Ergebnis ist ein Archiv (`tar.zst` Standard) mit Manifest und Prüfsumme.
4. `GET /api/exports/{id}/download` lädt die Datei herunter.

### Inhalt

Das Exportarchiv enthält:

- **Manifest** (JSON): Liste der Dokumente, Chunks, Metadaten
- **Chunk-Texte** (aus `document_chunks.text`)
- **Prüfsumme** (SHA-256)

### Tabelle `exports`

| Spalte | Beschreibung |
| --- | --- |
| export_id | UUID |
| filename | Dateiname |
| format | `tar.zst` |
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

`POST /api/import` nimmt ein zuvor exportiertes Archiv entgegen und stellt
Dokumente und Chunks wieder her.

Der Import (`ExportService::import()`) läuft in einer Transaktion und
rekonstruiert Dokumente aus dem Manifest. Vektoren werden – soweit im Export
enthalten – nach Milvus zurückgeschrieben; andernfalls müssen Dokumente neu
indiziert werden.

## Oberfläche

In der Ansicht **Export / Import**:

- **Export erstellen** – Format wählen, Export anlegen
- **Exportstatus** – Liste mit Status und Download-Link
- **Import** – Archiv auswählen und hochladen

## Dateiablage

Exporte liegen im Container-/Host-Verzeichnis:

```text
data/exports/
```

(`EXPORT_ROOT=/srv/data/exports`, gemappt auf `${DATA_DIR}/exports`).

## Hinweise

- Exporte bleiben bis zur manuellen Löschung erhalten.
- Die Quelldokumente (`data/input/`) sind **nicht** Teil des Exports – nur die
  indizierten Inhalte (Text/Chunks/Metadaten).
