# ORIGINALDOKUMENTE UND QUELLREFERENZEN

Dieses Kapitel beschreibt, wie die Anwendung Originaldokumente byte-genau
speichert, versioniert, referenziert und zusammen mit den Vektordaten
exportiert bzw. importiert. Es ergänzt [DATABASE.md](DATABASE.md),
[MILVUS.md](MILVUS.md) und [EXPORT_IMPORT.md](EXPORT_IMPORT.md).

## 1. Speicherung

Jede verarbeitete Originaldatei wird **byte-genau** und **ohne Informations-
verlust** in der Tabelle `document_blobs` abgelegt. Die Datei wird dabei nicht
verändert, sondern als Base64-kodierter Byte-Strom gespeichert. Es gilt die
zentrale technische Regel: Ein Dateipfad ist **niemals** die Identität eines
Dokuments – der stabile Zusammenhang entsteht ausschließlich über IDs und
Hashes.

```text
                    ┌──────────────────────┐
                    │  Originaldokument    │
                    │  Base64 in MySQL     │
                    └──────────┬───────────┘
                               │
                         document_version_id
                               │
                               ▼
                    ┌──────────────────────┐
                    │       MySQL          │
                    │ Metadata / Chunks    │
                    └──────────┬───────────┘
                               │
                           chunk_id
                               │
                               ▼
                    ┌──────────────────────┐
                    │       Milvus         │
                    │       Vector         │
                    └──────────────────────┘
```

### Base64

- Die Originalbytes werden streng nach RFC 4648 als Base64 kodiert.
- Die Kodierung erfolgt zeichengenau; beim Dekodieren wird strikt geprüft
  (ungültige Zeichen, falsche Länge, ungültige Padding-Bits werden abgelehnt).
- Die gemeinsame Helper-Klasse `App\Core\Base64` zentralisiert das strikte
  Dekodieren (`decode()`), das Streaming-Hashing (`sha256()`) und die
  konstantzeitige Prüfsummen-Verifikation (`verifySha256()`).
- Der Streaming-Hash arbeitet in Chunks, deren Größe durch 4 teilbar ist, damit
  eine Base64-Vierergruppe nie über eine Chunk-Grenze geteilt wird.

### Hashing

- Für jede Originaldatei wird ein **SHA-256**-Hash über die **Originalbytes**
  berechnet und in `documents.file_hash` sowie `document_blobs.sha256`
  gespeichert.
- Der Vergleich erfolgt konstantzeit (`hash_equals`), um Timing-Angriffe
  auszuschließen.
- Der Hash dient als Duplikaterkennung und als Integritätsanker über
  Speicherung, Export, Import und Restore hinweg.

## 2. Versionierung

- `document_id` ist die **stabile logische Identität** eines Dokuments.
- `document_version_id` ist die **unveränderliche Identität** genau einer
  gespeicherten Version.
- Jede Version besitzt eigene Blob-, Chunk- und Vektor-Daten.
- Beim erneuten Verarbeiten eines geänderten Dokuments wird die bisherige
  aktuelle Version auf `is_current = 0` gesetzt und eine neue Version mit
  `is_current = 1` sowie `version = version + 1` angelegt.
- Alte Versionen bleiben erhalten und sind über die Versions-API abrufbar.

## 3. MySQL-Struktur

### `document_blobs`

| Spalte | Beschreibung |
| --- | --- |
| `blob_id` | Technischer Primärschlüssel (AUTO_INCREMENT) |
| `document_id` | Logische Dokument-ID (UUID) |
| `document_version_id` | Versions-ID (UUID, eindeutig) |
| `encoding` | `base64` |
| `mime_type` | MIME-Typ der Originaldatei |
| `original_filename` | Ursprünglicher Dateiname |
| `file_size` | Byte-Größe der Originaldatei |
| `sha256` | SHA-256 der Originalbytes (64 Hex-Zeichen) |
| `base64_data` | Base64-kodierter Original-Byte-Strom (LONGTEXT) |
| `created_at` | Zeitstempel |

### `documents` (Erweiterung)

- `document_version_id`, `version`, `is_current` – Versionierung
- `blob_id` – Verweis auf den Blob der Version
- `metadata` – JSON-Metadaten (Seiten, Sprache, Autor, …)
- `document_chunks.vector_id` – stabiler Verweis auf den Milvus-Vektor

## 4. Milvus-Struktur

Jede Collection (`docvec_<modell>`) verwendet ein **stabil-ID-Schema**:

- `autoID = false`, Primärschlüssel `id` (VarChar, max_length 36)
- Der `id`-Wert entspricht exakt `document_chunks.vector_id` (eine App-UUID).
- Ausgabefelder: `id`, `document_id`, `document_version_id`, `chunk_id`,
  `job_id`, `source_path`, `filename`, `document_hash`, `embedding_model`,
  `embedding_dimension`, `chunk_index`, `page_start`, `page_end`.
- Der Chunk-Text verbleibt in MySQL (Source-of-Truth) und wird beim Abruf
  angereichert („hydriert“).

## 5. Beziehungen

```text
documents.document_id            (logisch, mehrfach je Version)
  └── documents.document_version_id  (eindeutig je Version)
        ├── document_blobs.document_version_id
        ├── document_chunks.document_id
        │      └── document_chunks.chunk_id
        │            └── document_chunks.vector_id  ==  Milvus.id
        └── (Metadaten, Export, Integrität)
```

Die Beziehung ist **bidirektional**:

- **Milvus → Original**: `vector_id` → `document_chunks.vector_id` →
  `document_version_id` → `document_blobs.base64_data`.
- **Original → Milvus**: `document_version_id` → `document_chunks` →
  `vector_id` → Milvus `id`.
- **Suchergebnis → Original**: Treffer liefert `chunk_id`/`document_version_id`;
  darüber werden Quell-Metadaten, Chunk-Text und das Original aufgelöst.

## 6. Quellenabruf

Die REST-API stellt dedizierte Quellen-Endpunkte bereit:

- `GET /api/documents/{id}/source` – Quell-Metadaten des Originals
- `GET /api/documents/{id}/versions` – alle Versionen
- `GET /api/documents/{id}/chunks` – Chunks der aktuellen Version
- `GET /api/documents/{id}/vectors` – Vektoren der aktuellen Version
- `GET /api/documents/{id}/download` – Originaldatei als binärer Download
- `GET /api/documents/{id}/metadata` – Metadaten
- `GET /api/vectors/{id}` – Vektor-Datensatz
- `GET /api/vectors/{id}/document` – zugehöriges Dokument (Gegenrichtung)
- `GET /api/chunks/{id}` – Chunk-Datensatz
- `GET /api/chunks/{id}/source` – Quelle eines Chunks
- `GET /api/source/{document_version_id}` – Quelle einer bestimmten Version

Der Download liefert die **exakten Originalbytes** (Base64 → Dekodierung) mit
korrektem `Content-Type`, `Content-Length` und `Content-Disposition`.

## 7. RAG-Nutzung

Für LLM/RAG-Kontexte stehen die Quelleninformationen direkt im Suchergebnis
bereit: `document_id`, `document_version_id`, `chunk_id`, `filename`,
`source_path`, `document_hash`, `embedding_model`, `embedding_dimension`,
`chunk_index`, `page_start`, `page_end` sowie der aus MySQL angereicherte
Chunk-Text. Damit kann ein Agent jede Antwort auf eine exakte, abrufbare
Originalstelle zurückführen.

## 8. Export

Der Export (`.tar.gz`) enthält zusätzlich zu Chunks/Metadaten:

- **Originale** als Base64 (`originals/` im Archiv)
- **Vektoren** (vollständige Float-Vektoren)
- **Beziehungen** (MySQL ↔ Milvus, `vector_id`/`chunk_id`/`document_version_id`)
- **Manifest** (JSON) mit Zählern und Einbettungsmodellen
- **Checksums** (SHA-256 je Datei und für das Gesamtarchiv)
- **Integritäts-Metadaten** für die spätere Prüfung

## 9. Import

Der Import liest das Archiv, verifiziert zuerst die Integrität (Manifest +
Checksums) und stellt anschließend Dokumente, Originale, Chunks und Vektoren
**ID-erhaltend** wieder her:

- Strategie `skip` (Standard): bereits vorhandene IDs werden übersprungen.
- Strategie `overwrite`: vorhandene IDs werden ersetzt.
- Das Ergebnis meldet `imported`, `reused`, `skipped`, `overwritten`,
  `conflicts`.

## 10. Integritätsprüfung

`GET /api/integrity` prüft zentral:

- Base64-Roundtrips (Dekodierung + SHA-256-Vergleich)
- Hashfehler (Blob-Hash vs. Dokument-Hash)
- Verwaiste Vektoren (Milvus ohne MySQL-Referenz)
- Fehlende Blobs (Version ohne Original)
- Fehlende Chunks/Vektoren (Version ohne Chunks bzw. Vektoren)
- Collections und Dimensionskonsistenz

## 11. Backup & Restore

- **Backup** = Export-Archiv (enthält Originale + Metadaten + Chunks +
  Vektoren + Beziehungen + Checksums).
- **Restore** = Import des Archivs (ID-erhaltend, mit Integritätsprüfung).
- Zusätzlich ist das MariaDB-Volume (`db_data`) als klassisches Datenbank-
  Backup geeignet.
- Nach Neustart, Backup, Restore, Export, Import und Transport bleibt die
  ID-/Hash-Beziehung eindeutig erhalten.
