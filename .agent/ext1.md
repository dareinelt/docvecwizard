# ERWEITERUNG ZUM BESTEHENDEN CODING-PROMPT

## Originaldokumente als Quellen speichern, referenzieren und gemeinsam mit Vektordaten exportieren/importieren

Diese Erweiterung ist verbindlicher Bestandteil des bestehenden Projekts.

Die bereits definierten Anforderungen, insbesondere:

* PHP ohne Framework
* Vanilla JavaScript
* MySQL/MariaDB
* Milvus
* Qwen3 Embeddings
* Docker
* vollständiger Offline-Betrieb
* Job-System
* Dokumentenverarbeitung
* Export/Import
* Tests und vollständige Verifikation

bleiben unverändert bestehen.

Die folgenden Anforderungen ergänzen und erweitern diese Spezifikation.

---

# 1. Ziel

Originaldokumente dürfen nach der Verarbeitung nicht verloren gehen.

Für jedes verarbeitete Dokument muss jederzeit eine eindeutige Verbindung zwischen folgenden Informationen möglich sein:

```text
Originaldokument
      ↕
MySQL-Datensatz
      ↕
Dokument / Chunk / Metadaten
      ↕
Milvus-Vektor
```

Ein Benutzer oder ein später angeschlossenes Agentensystem muss anhand eines Vektors bzw. eines Suchtreffers das zugehörige Originaldokument eindeutig identifizieren können.

Umgekehrt muss anhand eines Originaldokuments jederzeit festgestellt werden können:

* zu welchem Job es gehört,
* welche Dokumentversion verarbeitet wurde,
* welches Embedding-Modell verwendet wurde,
* welche Chunks daraus entstanden sind,
* welche Milvus-Vektoren dazu gehören,
* welche Collection verwendet wurde,
* welche Embedding-Dimension verwendet wurde.

---

# 2. Speicherung des Originaldokuments

Das Originaldokument muss zusätzlich zu den bisherigen Metadaten persistent gespeichert werden.

Für jedes Dokument:

```text
Originaldatei
    ↓
Base64
    ↓
MySQL
```

Die Binärdatei muss vor Speicherung korrekt als Base64 kodiert werden.

Wichtig:

Die Base64-Daten sind lediglich eine Transport-/Speicherrepräsentation.

Es muss jederzeit möglich sein:

```text
Base64
 ↓
Binary
 ↓
Originaldatei
```

ohne Informationsverlust wiederherzustellen.

---

# 3. Datenbankmodell erweitern

Die Tabelle `documents` bzw. eine sinnvoll getrennte Tabelle muss um die Originaldatei ergänzt werden.

Empfohlen ist eine separate Tabelle, beispielsweise:

```text
document_blobs
```

anstatt sehr große Base64-Daten direkt in die eigentliche Dokument-Metadatentabelle zu schreiben.

Beispiel:

```text
documents
---------
document_id
job_id
source_path
relative_path
filename
extension
mime_type
file_size
file_hash
created_at
modified_at
indexed_at
page_count
character_count
word_count
token_count_estimate
chunk_count
embedding_model
embedding_dimension
processing_status
processing_duration
error_message
blob_id
```

und:

```text
document_blobs
--------------
blob_id
document_id
encoding
mime_type
original_filename
file_size
sha256
base64_data
created_at
```

Die konkrete Struktur darf angepasst werden, sofern sie technisch sinnvoller ist.

---

# 4. Datenbanktyp und Größe berücksichtigen

Base64 vergrößert Binärdaten gegenüber der Originaldatei.

Deshalb darf kein ungeeigneter MySQL-Datentyp verwendet werden.

Prüfe insbesondere die maximal mögliche Größe.

Für `base64_data` ist ein geeigneter großer Binary-/Text-Datentyp zu verwenden, beispielsweise:

```text
LONGTEXT
```

oder eine technisch bessere Alternative, sofern die vollständige Base64-Anforderung eingehalten wird.

Die Entscheidung muss dokumentiert werden.

Bei großen Dokumenten muss getestet werden, dass:

* Insert funktioniert
* Select funktioniert
* Transaktion funktioniert
* Export funktioniert
* Restore funktioniert
* keine Daten abgeschnitten werden

---

# 5. Originaldatei unverändert erhalten

Die Originaldatei muss bytegenau erhalten bleiben.

Das bedeutet:

```text
Originaldatei
SHA-256
↓
Base64
↓
MySQL
↓
Base64 decode
↓
Rekonstruierte Datei
SHA-256
```

muss ergeben:

```text
SHA256_original == SHA256_reconstructed
```

Dies muss automatisiert getestet werden.

Nicht nur der extrahierte Text ist zu speichern.

Das tatsächliche Originaldokument muss erhalten bleiben.

---

# 6. Unveränderbarkeit der Quelle

Nach erfolgreicher Speicherung soll die Originaldatei logisch als unveränderliche Quelle betrachtet werden.

Wenn eine Datei im Quellverzeichnis geändert wird:

```text
alte Datei
→ SHA-256 A

neue Datei
→ SHA-256 B
```

darf die alte Dokumentversion nicht einfach überschrieben werden.

Stattdessen muss ein neues Dokument bzw. eine neue Dokumentversion entstehen.

---

# 7. Dokumentversionierung

Implementiere ein nachvollziehbares Versionsmodell.

Beispiel:

```text
document_id:
DOC-12345

version:
1

hash:
abc...

↓

gleicher Pfad
aber geänderter Inhalt

version:
2

hash:
def...
```

Eine Version muss eindeutig identifizierbar sein.

Empfohlen:

```text
document_id
document_version_id
```

oder ein gleichwertiges Modell.

Jede Version besitzt ihr eigenes Originaldokument und ihre eigenen Chunks/Vektoren.

Alte Versionen dürfen nicht versehentlich auf neue Vektoren zeigen.

---

# 8. Eindeutige globale IDs

Die Beziehungen dürfen nicht ausschließlich über Dateipfade hergestellt werden.

Dateipfade können sich ändern.

Verwende stabile IDs.

Mindestens:

```text
document_id
document_version_id
chunk_id
vector_id
job_id
collection_id
```

Die IDs müssen über Export/Import hinweg erhalten bleiben.

---

# 9. Referenz zwischen MySQL und Milvus

Jeder Milvus-Vektor muss eindeutig auf die Quelle zurückverweisen können.

Mindestens folgende Felder in Milvus vorsehen:

```text
document_id
document_version_id
chunk_id
job_id
source_path
filename
```

Zusätzlich sinnvoll:

```text
document_hash
embedding_model
embedding_dimension
page_start
page_end
chunk_index
```

Wichtig:

Der primäre Zusammenhang muss über stabile IDs erfolgen.

`source_path` ist lediglich ergänzende Information.

---

# 10. Bidirektionale Zuordnung

Es muss jederzeit möglich sein:

## Von Milvus → Original

```text
vector_id
 ↓
chunk_id
 ↓
document_version_id
 ↓
document_id
 ↓
document_blob
 ↓
Originaldatei
```

## Von Original → Milvus

```text
document_id
 ↓
document_version_id
 ↓
chunks
 ↓
vector_ids
 ↓
Milvus
```

## Von Suchergebnis → Original

```text
Similarity Search
 ↓
Milvus Treffer
 ↓
document_version_id
 ↓
MySQL
 ↓
Originaldokument
```

Diese Funktionalität muss durch API-Endpunkte unterstützt werden.

---

# 11. API-Erweiterungen

Erweitere die API beispielsweise um:

```text
GET /api/documents/{id}
GET /api/documents/{id}/source
GET /api/documents/{id}/versions
GET /api/documents/{id}/chunks
GET /api/documents/{id}/vectors
GET /api/documents/{id}/download
GET /api/documents/{id}/metadata
```

Zusätzlich sinnvoll:

```text
GET /api/vectors/{id}
GET /api/vectors/{id}/document
GET /api/chunks/{id}
GET /api/chunks/{id}/source
```

Die konkrete API darf anders strukturiert werden, muss aber dieselbe Funktionalität bereitstellen.

---

# 12. Originaldokument aus Webinterface öffnen

Das Webinterface muss eine Dokumentquelle anzeigen können.

Beispielsweise:

```text
Dokument:
Geschäftsbericht_2025.pdf

Version:
2

Quelle:
/Dokumente/Finanzen/Geschäftsbericht_2025.pdf

Hash:
SHA-256 ...

Seiten:
87

Chunks:
342

Vektoren:
342

Embedding:
Qwen3-Embedding-4B

[Original öffnen]
[Original herunterladen]
[Metadaten]
[Chunks anzeigen]
[Vektoren anzeigen]
```

---

# 13. Originaldokument anzeigen

Wenn technisch möglich, soll das Originaldokument direkt im Browser angezeigt werden.

Beispielsweise:

* PDF → Browser-PDF-Viewer
* Bilder → Bildanzeige
* Text → Textanzeige
* andere Dokumenttypen → Download bzw. systemgeeignete Darstellung

Die Anwendung darf hierfür keine externe Online-Ressource benötigen.

Für nicht direkt darstellbare Formate muss mindestens:

```text
Original herunterladen
```

angeboten werden.

---

# 14. Quellenreferenzen bei Suchergebnissen

Die spätere Nutzung durch ein LLM/RAG-System ist ein zentraler Anwendungsfall.

Ein Suchtreffer muss daher neben dem eigentlichen Text auch Quelleninformationen liefern.

Beispiel:

```json
{
  "vector_id": "vec-123",
  "document_id": "doc-123",
  "document_version_id": "doc-123-v2",
  "chunk_id": "chunk-456",
  "score": 0.8734,
  "text": "...",
  "source": {
    "filename": "Geschaeftsbericht_2025.pdf",
    "path": "/Finanzen/Geschaeftsbericht_2025.pdf",
    "page_start": 42,
    "page_end": 43,
    "sha256": "...",
    "mime_type": "application/pdf"
  }
}
```

Die genaue API-Struktur darf angepasst werden.

Wichtig ist, dass ein LLM-Agent aus einem Suchtreffer eine belastbare Quelle erhalten kann.

---

# 15. Seitenreferenzen

Wenn das Dokument Seiten besitzt, muss die Seiteninformation soweit möglich erhalten bleiben.

Für jeden Chunk:

```text
page_start
page_end
```

Beispielsweise:

```text
Chunk 52
Seite 17–18
```

Bei Dokumenten ohne Seitenstruktur:

```text
page_start = null
page_end = null
```

Nicht künstlich Seitenzahlen erfinden.

---

# 16. Position innerhalb des Dokuments

Wenn der verwendete Converter dies ermöglicht, zusätzlich speichern:

```text
character_start
character_end
```

bzw. vergleichbare Positionsinformationen.

Damit kann später nachvollzogen werden, welcher Teil des Originals zum Chunk geführt hat.

Optional:

```text
paragraph_start
paragraph_end
section
heading
```

Diese Informationen sind besonders wertvoll für Quellenangaben.

---

# 17. Dokumentdarstellung im UI

Auf einer Dokumentdetailseite soll eine Quellen-/Beziehungsansicht vorhanden sein.

Beispiel:

```text
┌──────────────────────────────────────────────┐
│ Originaldokument                             │
│                                              │
│ Geschäftsbericht 2025.pdf                    │
│                                              │
│ SHA-256: abcdef...                           │
│ Version: 2                                   │
│ Größe: 18.4 MB                               │
│ Seiten: 87                                   │
│                                              │
│ [Original öffnen] [Download]                 │
├──────────────────────────────────────────────┤
│ Verarbeitung                                 │
│                                              │
│ Modell: Qwen3-Embedding-4B                   │
│ Dimension: ...                               │
│ Chunks: 342                                  │
│ Vektoren: 342                                │
├──────────────────────────────────────────────┤
│ Quellen / Vektoren                            │
│                                              │
│ Chunk 1 → Vector abc                         │
│ Chunk 2 → Vector def                         │
│ Chunk 3 → Vector ghi                         │
└──────────────────────────────────────────────┘
```

---

# 18. Chunkdetailseite

Ein Chunk muss mindestens anzeigen:

```text
Chunk-ID
Document-ID
Version
Quelle
Seite
Text
Tokenanzahl
Embedding-Modell
Dimension
Vector-ID
Milvus Collection
```

Aktionen:

```text
[Original öffnen]
[Seite öffnen]
[Vektor anzeigen]
[Metadaten]
```

---

# 19. Milvus-Referenzmodell

Das Milvus-Schema muss so ausgelegt sein, dass die Beziehung zu MySQL dauerhaft rekonstruierbar bleibt.

Mindestens:

```text
vector_id
document_id
document_version_id
chunk_id
job_id
embedding_model
embedding_dimension
source_hash
page_start
page_end
chunk_index
text
metadata
vector
```

Der `vector_id` muss stabil und eindeutig sein.

Keine ausschließlich automatisch generierte ID verwenden, wenn diese nach Export/Import nicht zuverlässig erhalten bleibt.

---

# 20. MySQL als Source-of-Truth für Dokumente

MySQL ist für die Dokument- und Quellmetadaten verantwortlich.

Milvus ist für Vektordaten und Vektorsuche verantwortlich.

Die Anwendung muss beide Systeme logisch zusammenführen.

Es darf niemals vorausgesetzt werden, dass ein Dateipfad die Beziehung herstellt.

Primäre Beziehung:

```text
Stable IDs
```

Sekundäre Informationen:

```text
Path
Filename
Hash
```

---

# 21. Konsistenzprüfung

Implementiere eine Funktion:

```text
Datenintegrität prüfen
```

Sie soll prüfen:

* existiert jedes Dokument in MySQL?
* existiert das zugehörige Blob?
* stimmt SHA-256?
* existieren die Chunks?
* existieren die zugehörigen Milvus-Vektoren?
* stimmen die IDs?
* stimmt die Collection?
* stimmt das Modell?
* stimmt die Dimension?
* fehlen Vektoren?
* existieren verwaiste Vektoren?
* existieren verwaiste Dokumente?

Beispiel:

```text
Dokumente:
12.482

Dokumente ohne Blob:
0

Chunks:
123.442

Chunks ohne Vektor:
0

Vektoren ohne Dokument:
0

Hashfehler:
0

Inkonsistenzen:
0
```

---

# 22. Reparaturfunktion

Wenn möglich, implementiere zusätzlich:

```text
Integrität prüfen
```

und:

```text
Inkonsistenzen analysieren
```

Eine automatische Reparatur darf nur mit Vorsicht erfolgen.

Keine Daten löschen, nur weil eine Referenz fehlt.

Vor destruktiven Reparaturen:

* Backup
* explizite Bestätigung
* ausführliches Log

---

# 23. Export muss Originaldokumente enthalten

Der bisherige Export wird erweitert.

Ein Export muss nicht nur Vektoren enthalten.

Er muss enthalten:

```text
Originaldokumente
MySQL-Metadaten
Dokumentversionen
Chunks
Milvus-Daten
Collection-Schema
Embedding-Modellinformationen
Jobs
Konfigurationen, soweit erforderlich
Beziehungen/IDs
Manifest
Checksums
```

Der Export muss einen vollständigen Wiederaufbau ermöglichen.

---

# 24. Empfohlenes Exportformat

Beispiel:

```text
export_2026-09-30_205100.tar.zst
```

Inhalt:

```text
manifest.json

documents/
  DOC-000001/
    metadata.json
    original.b64
    checksum.sha256
    chunks.json

  DOC-000002/
    metadata.json
    original.b64
    checksum.sha256
    chunks.json

mysql/
  schema.sql
  data.sql
  documents.sql
  jobs.sql
  chunks.sql

milvus/
  collections.json
  schema.json
  vectors/
    ...

config/
  embedding-models.json

checksums/
  SHA256SUMS
```

Die konkrete Struktur darf optimiert werden.

Sie muss jedoch vollständig, nachvollziehbar und langfristig lesbar sein.

---

# 25. Exportmanifest

Das Manifest muss mindestens enthalten:

```json
{
  "export_format_version": "1.0",
  "application_version": "...",
  "created_at": "...",
  "source_system": "...",
  "documents": 12482,
  "document_versions": 12591,
  "chunks": 123442,
  "vectors": 123442,
  "collections": 3,
  "original_bytes": 123456789,
  "base64_bytes": 164609052,
  "compression": "zstd",
  "checksums": {
    "algorithm": "SHA-256"
  }
}
```

Zusätzlich Modellinformationen.

---

# 26. Exportintegrität

Nach Export:

1. Archiv öffnen
2. Manifest lesen
3. Anzahl Dokumente prüfen
4. Anzahl Versionen prüfen
5. Anzahl Chunks prüfen
6. Anzahl Vektoren prüfen
7. Checksums prüfen
8. Base64 decodieren
9. SHA-256 der rekonstruierten Originaldateien prüfen
10. Beziehungen prüfen
11. Milvus-Daten prüfen

Kein Export darf als erfolgreich gelten, bevor diese Prüfungen erfolgreich abgeschlossen sind.

---

# 27. Import

Beim Import:

```text
Archiv
 ↓
Checksum
 ↓
Manifest
 ↓
Kompatibilität
 ↓
Originaldokumente
 ↓
MySQL
 ↓
Milvus
 ↓
Beziehungen prüfen
 ↓
Integritätsprüfung
```

Import darf keine bestehenden Daten blind überschreiben.

Konfliktstrategien vorsehen:

* neuer Datensatz
* vorhandenen Datensatz überspringen
* identische Version wiederverwenden
* Konflikt melden
* explizites Überschreiben nach Bestätigung

Standardmäßig sicherste Variante verwenden.

---

# 28. Import-ID-Erhalt

Die IDs aus dem Export müssen grundsätzlich erhalten bleiben können.

Beispiel:

```text
document_id
document_version_id
chunk_id
vector_id
job_id
```

dürfen beim Transport nicht einfach neu erzeugt werden, wenn dadurch die Beziehungen verloren gehen.

Wenn interne technische IDs nicht übernommen werden können, muss eine persistente Mapping-Tabelle exportiert werden.

---

# 29. Import nach anderem System

Der Export soll auf einem zweiten, kompatiblen System wiederherstellbar sein.

Test:

System A:

```text
Dokument
→ MySQL
→ Milvus
```

Export

↓

System B:

```text
Import
→ MySQL
→ Milvus
```

Danach muss gelten:

```text
Dokument-ID identisch
Version identisch
Hash identisch
Chunk-ID identisch
Vector-ID identisch
Modell identisch
Dimension identisch
```

soweit das Exportformat dies vorsieht.

---

# 30. Transport zu einem Agenten

Der Export ist ausdrücklich für spätere Nutzung durch einen Agenten oder ein anderes RAG-/LLM-System gedacht.

Deshalb muss eine maschinenlesbare Quellenstruktur vorhanden sein.

Ein Agent muss aus einem Suchtreffer mindestens erhalten können:

```text
document_id
document_version_id
chunk_id
vector_id
filename
source_path
page_start
page_end
document_hash
mime_type
```

Optional:

```text
section
heading
character_start
character_end
document_created_at
document_modified_at
```

---

# 31. Agentenfreundlicher Quellenendpunkt

Zusätzlich einen möglichst stabilen Endpoint vorsehen:

```text
GET /api/source/{document_version_id}
```

Antwort beispielsweise:

```json
{
  "document_id": "...",
  "document_version_id": "...",
  "filename": "...",
  "mime_type": "application/pdf",
  "sha256": "...",
  "page_count": 87,
  "source_available": true,
  "download_endpoint": "/api/documents/.../download",
  "chunks": [
    {
      "chunk_id": "...",
      "page_start": 42,
      "page_end": 43
    }
  ]
}
```

---

# 32. LLM/RAG-Kontext

Bei einer späteren Vektorsuche muss der Retrieval-Output nicht nur den Chunk-Text liefern.

Er muss Quellenmetadaten mitliefern.

Beispiel:

```json
{
  "text": "....",
  "score": 0.91,
  "source": {
    "document_id": "...",
    "document_version_id": "...",
    "chunk_id": "...",
    "filename": "vertrag.pdf",
    "page_start": 12,
    "page_end": 13,
    "sha256": "...",
    "download_endpoint": "..."
  }
}
```

Damit kann ein LLM-Agent später belastbare Quellenreferenzen erzeugen.

---

# 33. Datenschutz und Sicherheit der Originale

Originaldokumente können vertrauliche Informationen enthalten.

Deshalb:

* keine unnötige externe Übertragung
* keine Cloud-Speicherung
* keine Telemetrie
* Zugriff nur innerhalb der Anwendung
* sichere Dateiberechtigungen
* keine Base64-Daten in Logs
* keine Dokumentinhalte in normalen Debug-Logs
* keine vollständigen Dokumente in Fehlermeldungen

---

# 34. Speicherverbrauch

Da Base64 den Speicherbedarf erhöht, muss das Dashboard dies transparent darstellen.

Mindestens:

```text
Originalgröße
Base64-Größe
MySQL-Dokumentenspeicher
Milvus-Speicher
Gesamtspeicher
```

Beispiel:

```text
Originaldokumente       18.4 GB
Base64-Repräsentation   24.5 GB
MySQL-Metadaten          1.2 GB
Milvus                  11.8 GB
--------------------------------
Gesamt                  55.9 GB
```

Nur tatsächlich gemessene Werte anzeigen.

---

# 35. Backup

Zusätzlich muss die Dokumentdatenbank als Bestandteil eines Backups betrachtet werden.

Dokument-Backup umfasst:

* MySQL
* Original-Base64
* Milvus
* Konfiguration
* Exportmanifest

Dokumentieren:

* Backup
* Restore
* Integritätsprüfung

---

# 36. Tests

Zusätzliche verpflichtende Tests:

## Base64-Roundtrip

```text
Original
→ Base64
→ MySQL
→ Decode
→ SHA-256
```

Erwartung:

```text
Hash identisch
```

## Referenztest

```text
Milvus vector
→ document_version_id
→ MySQL
→ Blob
→ Original
```

Erwartung:

```text
Original korrekt gefunden
```

## Gegenrichtung

```text
Original
→ document_id
→ chunks
→ vector_ids
→ Milvus
```

Erwartung:

```text
alle Vektoren korrekt gefunden
```

## Versions-Test

Dokument ändern:

```text
Version 1
→ Version 2
```

Prüfen:

* Original V1 bleibt erhalten
* Original V2 bleibt erhalten
* V1-Vektoren bleiben V1 zugeordnet
* V2-Vektoren bleiben V2 zugeordnet

## Export-Test

```text
System A
→ Export
→ System B
→ Import
```

Danach vollständige Integritätsprüfung.

---

# 37. Fehlerfälle

Teste mindestens:

* beschädigte Base64-Daten
* falscher SHA-256
* fehlender Blob
* fehlender Chunk
* fehlender Milvus-Vektor
* Vektor ohne MySQL-Dokument
* falsche Dimension
* falsche Collection
* unbekannte document_id
* unbekannte version_id
* inkompatibles Exportformat
* unvollständiges Archiv
* abgebrochener Import

Das System darf solche Situationen nicht stillschweigend ignorieren.

---

# 38. UI-Erweiterungen

Dokumentdetailseite:

```text
Original
Metadaten
Versionen
Chunks
Vektoren
Quelle
Speicher
Integrität
```

Zusätzlich:

```text
[Original öffnen]
[Download]
[Versionen]
[Chunks]
[Vektoren]
[Integrität prüfen]
```

Bei Integritätsfehlern deutlich warnen.

---

# 39. Statistik-Erweiterungen

Zusätzlich anzeigen:

* Originalspeicher
* Base64-Speicher
* durchschnittliche Base64-Overhead
* Dokumentversionen
* Dokumente mit mehreren Versionen
* Vektoren pro Dokument
* Chunks pro Dokument
* Dokumente ohne Vektoren
* Vektoren ohne Dokument
* Integritätsfehler

---

# 40. Dokumentation erweitern

Die bestehende Dokumentation muss um ein Kapitel ergänzt werden:

```text
ORIGINALDOKUMENTE UND QUELLREFERENZEN
```

Beschreibe:

* Speicherung
* Base64
* Hashing
* Versionierung
* MySQL-Struktur
* Milvus-Struktur
* Beziehungen
* Quellenabruf
* RAG-Nutzung
* Export
* Import
* Integritätsprüfung
* Backup
* Restore

---

# 41. PDF-Dokumentation erweitern

Die DIN-A4-PDF muss zusätzliche Screenshots enthalten:

1. Dokumentdetail
2. Original öffnen
3. Versionen
4. Chunkdetails
5. Vektorbeziehung
6. Quellenreferenz
7. Speicheraufteilung
8. Integritätsprüfung
9. Export
10. Import
11. erfolgreiche Restore-Prüfung

Alle Screenshots müssen aus der tatsächlich laufenden Anwendung stammen.

Alle Tabellen und Bilder müssen vollständig innerhalb der DIN-A4-Seite liegen.

---

# 42. Abnahmekriterien

Die Erweiterung gilt erst als fertig, wenn:

* [ ] Originaldatei wird vollständig gespeichert
* [ ] Base64 wird korrekt erzeugt
* [ ] Base64-Roundtrip funktioniert
* [ ] SHA-256 bleibt identisch
* [ ] Originalversionen bleiben erhalten
* [ ] stabile IDs vorhanden
* [ ] MySQL ↔ Milvus Beziehung funktioniert
* [ ] Milvus → Original funktioniert
* [ ] Original → Milvus funktioniert
* [ ] Quelleninformationen im Retrieval verfügbar sind
* [ ] Seitenreferenzen vorhanden sind, soweit ermittelbar
* [ ] Original im Webinterface abrufbar ist
* [ ] Export enthält Originale
* [ ] Export enthält Metadaten
* [ ] Export enthält Chunks
* [ ] Export enthält Vektoren
* [ ] Export enthält Beziehungen
* [ ] Export enthält Checksums
* [ ] Import funktioniert
* [ ] IDs werden erhalten
* [ ] Import in zweites System funktioniert
* [ ] Integritätsprüfung funktioniert
* [ ] Fehlerfälle getestet
* [ ] Backup/Restore getestet
* [ ] Speicherstatistik erweitert
* [ ] Dokumentation erweitert
* [ ] DIN-A4-PDF erweitert
* [ ] alle neuen Funktionen tatsächlich getestet

---

# 43. Wichtigste technische Regel

Die Anwendung darf niemals davon ausgehen, dass ein Dateipfad die Identität eines Dokuments darstellt.

Der stabile Zusammenhang muss über IDs und Hashes hergestellt werden.

Die zentrale Beziehung lautet:

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

Diese Beziehung muss nach:

* Neustart
* Backup
* Restore
* Export
* Import
* Transport auf ein anderes System

weiterhin eindeutig funktionieren.

---

# 44. Abschlussbericht

Erweitere den bestehenden Abschlussbericht um:

```text
ORIGINALDOKUMENTE
=================
Anzahl Originale:
Anzahl Versionen:
Originalspeicher:
Base64-Speicher:

REFERENZEN
==========
Dokumente mit Vektoren:
Vektoren mit Dokumentreferenz:
Vektoren ohne Dokument:
Dokumente ohne Vektoren:

INTEGRITÄT
==========
Base64-Roundtrips:
Hashfehler:
Verwaiste Vektoren:
Fehlende Blobs:
Fehlende Chunks:

EXPORT/IMPORT
=============
Export getestet:
Import getestet:
Restore getestet:
IDs erhalten:
Checksums geprüft:

ERGEBNIS
========
PASS / FAIL
```

Wichtig:

Keine Funktion als verifiziert markieren, wenn sie nicht tatsächlich ausgeführt und geprüft wurde.

---

# 45. Arbeitsweise

Diese Erweiterung ist nicht nur zu dokumentieren, sondern tatsächlich in die bestehende Anwendung zu integrieren.

Arbeite wieder strikt nach:

Anforderung
→ Implementierung
→ Test
→ Fehleranalyse
→ Korrektur
→ Regressionstest
→ Verifikation
→ Dokumentation

Beginne mit dem Datenmodell und dem Base64-Roundtrip.

Danach:

1. Dokumentversionierung
2. stabile IDs
3. MySQL ↔ Milvus-Verknüpfung
4. Quellen-API
5. UI
6. Export
7. Import
8. Integritätsprüfung
9. Backup/Restore
10. End-to-End-Test
11. PDF-Dokumentation

Jeder Schritt muss mit der lokal verfügbaren Docker-Umgebung tatsächlich getestet werden.
