# Dokumentenverarbeitung

## Überblick

Die Dokumentenverarbeitung ist eine Pipeline über mehrere Dienste:

```text
Datei (data/input)
  → Scan & Dedupe (worker, SHA-256)
  → Konvertierung (converter → Text/Markdown)
  → Chunking (worker, Chunker)
  → Embedding (embedding → Vektoren)
  → Speicherung (milvus + MariaDB)
```

## Unterstützte Formate

Aus `ProcessingService::SUPPORTED_EXTENSIONS`:

| Kategorie | Endungen | Konvertierung |
| --- | --- | --- |
| PDF | `.pdf` | pdftotext (Poppler) |
| Word (modern) | `.docx` | Pandoc |
| Word (legacy) | `.doc` | LibreOffice → PDF → pdftotext |
| OpenDocument Text | `.odt` | Pandoc |
| Rich Text | `.rtf` | Pandoc |
| PowerPoint (modern) | `.pptx` | LibreOffice → PDF → pdftotext |
| PowerPoint (legacy) | `.ppt` | LibreOffice → PDF → pdftotext |
| OpenDocument Präsentation | `.odp` | LibreOffice → PDF → pdftotext |
| HTML | `.html`, `.htm` | Pandoc |
| EPUB | `.epub` | Pandoc |
| Excel | `.xlsx`, `.xls` | LibreOffice → CSV |
| OpenDocument Tabelle | `.ods` | LibreOffice → CSV |
| CSV | `.csv` | als Text |
| Text/Markdown | `.txt`, `.md`, `.markdown` | als Text |

## Konverter-Dienst

`converter/src/main.py` – FastAPI mit einem Endpunkt `POST /convert`.

Interne Werkzeuge:

- **Pandoc** für DOCX/RTF/ODT/HTML/EPUB/Markdown
- **LibreOffice** (headless) für PPTX/PPT/ODP/DOC/XLSX/XLS/ODS
- **Poppler** (`pdftotext`) für PDF

Sicherheit: Der Konverter prüft, dass der angeforderte Pfad innerhalb der
erlaubten Wurzeln (`INPUT_ROOT`, `STAGING_ROOT`) liegt (`_allowed_roots()`).

## Chunking

Der `Chunker` (`app/src/Services/Chunker.php`) zerlegt Text in überlappende
Chunks:

| Parameter | Standard | Beschreibung |
| --- | --- | --- |
| max_tokens | 512 | Zielgröße |
| overlap_tokens | 64 | Überlappung |
| hard_max_tokens | 1024 | harte Obergrenze |

Die Token-Schätzung ist deterministisch und heuristisch (ohne externen
Tokenizer): CJK-Zeichen zählen einzeln, lateinische Wörter ≈ 4 Zeichen/Token.
Dadurch funktioniert sie für CJK und lange deutsche Komposita gleichermaßen.

Chunks werden mit `chunk_index`, `page_start`, `page_end`, `text_length`,
`token_count` und dem Volltext in `document_chunks` gespeichert.

## Deduplizierung

Jede Datei wird per **SHA-256** (`file_hash`) gehasht. Bereits bekannte Hashes
werden übersprungen, sodass identische Dateien nicht doppelt indiziert werden.

## Verarbeitungsstatus

| Status | Bedeutung |
| --- | --- |
| `DISCOVERED` | Datei erkannt, noch nicht verarbeitet |
| `PROCESSING` | wird gerade verarbeitet |
| `COMPLETED` | erfolgreich abgeschlossen |
| `FAILED` | Fehler aufgetreten (Details in `error_message`) |

## Fehlerbehandlung

Fehler werden in `processing_errors` protokolliert und am Dokument als
`FAILED` mit `error_message` markiert. Der Job läuft weiter und zählt
`documents_failed` bzw. `error_count` hoch.

## Metadaten

Nach der Verarbeitung werden gespeichert:

- `page_count`, `character_count`, `word_count`
- `token_count_estimate`, `chunk_count`
- `embedding_model`, `embedding_dimension`
- `indexed_at`, `processing_duration`
