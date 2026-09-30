# Auftrag: Entwicklung einer vollständig offlinefähigen Dokument-Embedding-Webanwendung

## 1. Ziel

Entwickle eine produktionsnahe, vollständig containerisierte Webanwendung zur Verarbeitung lokaler Dokumentbestände.

Die Anwendung soll:

1. lokale Dokumentverzeichnisse einlesen,
2. optional rekursiv Unterverzeichnisse durchsuchen,
3. unterstützte Dokumentformate in einen geeigneten Text-/Markdown-Zwischenschritt überführen,
4. Dokumente segmentieren/chunken,
5. mit einem vom Benutzer auswählbaren Qwen3-Embedding-Modell embeddieren,
6. die Embeddings und zugehörigen Metadaten in Milvus speichern,
7. Jobs persistent verwalten und später fortsetzen können,
8. einzelne Dokumente zusätzlich per Upload verarbeiten können,
9. Fortschritt und Systemressourcen live anzeigen,
10. Statistiken über Dokumente und Vektordaten bereitstellen,
11. komplette Vektordatenbestände komprimiert exportieren können,
12. vollständig ohne WAN-/Internetverbindung betrieben werden können,
13. über HTTPS mit selbst signiertem Zertifikat erreichbar sein,
14. vollständig per Docker Compose betrieben werden,
15. umfassend dokumentiert und reproduzierbar testbar sein.

Der gesamte Entwicklungsprozess muss nach dem Prinzip

> **Implementieren → testen → verifizieren → Ergebnis dokumentieren → erst dann nächsten Schritt beginnen**

durchgeführt werden.

Kein größerer Funktionsblock gilt als fertig, bevor seine Funktionalität tatsächlich getestet wurde.

---

# 2. Technische Randbedingungen

## 2.1 Backend

* PHP
* keine PHP-Webframeworks
* Vanilla PHP
* MySQL/MariaDB für:

  * Konfiguration
  * Jobs
  * Dokumentmetadaten
  * Statusinformationen
  * Verarbeitungshistorie
  * Statistiken
  * Benutzer-/Systemeinstellungen, sofern erforderlich
* Milvus für Vektordaten
* REST/API-Kommunikation zwischen den Komponenten
* sauber getrennte Verantwortlichkeiten

## 2.2 Frontend

* Vanilla JavaScript
* HTML5
* CSS3
* keine Frontend-Frameworks
* keine Abhängigkeit von CDN-Ressourcen
* keine externe Webfont-Abhängigkeit
* alle benötigten JS/CSS/Icons lokal im Projekt
* moderne, professionelle Benutzeroberfläche
* Responsive Design
* Dark/Light-Mode darf optional vorgesehen werden
* UI muss auch auf typischen 1366×768- und 1920×1080-Displays gut funktionieren

## 2.3 Offline-Anforderung

Die Anwendung muss vollständig ohne WAN-Verbindung funktionieren.

Das bedeutet insbesondere:

* kein CDN
* keine externen JavaScript-Bibliotheken zur Laufzeit
* keine Google Fonts
* keine externen API-Aufrufe
* keine Telemetrie
* keine Cloud-Dienste
* keine Lizenzprüfung über das Internet
* keine Online-Aktualisierungspflicht
* keine Runtime-Abhängigkeit von github.com
* keine Runtime-Abhängigkeit von Hugging Face
* keine Runtime-Abhängigkeit von OpenAI oder anderen LLM-Anbietern

Alle Modelle, Libraries und sonstigen Runtime-Abhängigkeiten müssen lokal im Docker-Setup vorhanden sein.

Wenn für die erstmalige Installation Downloads erforderlich sind, muss dies ausschließlich als **Build-/Deployment-Schritt** betrachtet werden. Der fertige Stack muss danach offline funktionieren.

---

# 3. Containerarchitektur

Erstelle mindestens folgende Container:

```text
app
web
db
milvus
embedding
document-converter
```

Zusätzliche Container sind erlaubt und ausdrücklich erwünscht, wenn sie die Architektur verbessern.

Beispielsweise:

```text
app
web
db
milvus
embedding
document-converter
worker
```

Falls Milvus zusätzliche Abhängigkeiten wie etcd und MinIO benötigt, diese ebenfalls korrekt integrieren.

Die finale Architektur soll sauber dokumentiert werden.

Beispiel:

```text
                    ┌──────────────────────┐
                    │       Browser        │
                    └──────────┬───────────┘
                               │ HTTPS
                               ▼
                    ┌──────────────────────┐
                    │        Web           │
                    │ nginx / TLS / static │
                    └──────────┬───────────┘
                               │
                               ▼
                    ┌──────────────────────┐
                    │        App           │
                    │ PHP / API / Jobs     │
                    └──────┬────────┬──────┘
                           │        │
             ┌─────────────┘        └─────────────┐
             ▼                                    ▼
      ┌──────────────┐                     ┌──────────────┐
      │    MySQL     │                     │    Milvus    │
      │ Config/Jobs  │                     │  Vektoren   │
      └──────────────┘                     └──────────────┘
                           ▲
                           │
                    ┌──────┴──────┐
                    │             │
                    ▼             ▼
             ┌────────────┐ ┌───────────────┐
             │ Embedding  │ │ Doc Converter │
             │ Qwen3      │ │ PDF/DOCX/PPTX │
             └────────────┘ └───────────────┘
```

---

# 4. Docker

Erstelle:

```text
docker-compose.yml
```

sowie bei Bedarf:

```text
docker-compose.dev.yml
docker-compose.prod.yml
```

Dockerfiles für alle eigenen Images.

Anforderungen:

* reproduzierbare Builds
* Healthchecks
* sinnvolle Restart-Policies
* Volumes für persistente Daten
* Netzwerke sauber trennen
* Secrets nicht fest in Images einbauen
* Konfiguration über `.env`
* keine unnötigen Root-Rechte
* Container müssen nach Möglichkeit mit einem nicht privilegierten Benutzer laufen
* sinnvolle CPU-/RAM-Limits dokumentieren
* Logs müssen nachvollziehbar sein

Docker steht lokal zum Testen zur Verfügung.

Nutze Docker aktiv zur Verifikation.

---

# 5. Qwen3 Embedding

Das Webinterface muss die verfügbaren Qwen3-Embedding-Modelle anzeigen.

Mindestens vorzusehen:

```text
Qwen3-Embedding-0.6B
Qwen3-Embedding-4B
Qwen3-Embedding-8B
```

Der Benutzer muss im Webinterface auswählen können, welches Modell aktiv verwendet wird.

## Wichtig

Die Embedding-Dimension muss **nicht hardcodiert angenommen** werden.

Für jedes Modell müssen die tatsächlichen Eigenschaften hinterlegt bzw. zur Laufzeit ermittelt werden.

Das System muss mindestens verwalten:

```text
model_name
model_version
dimension
max_input_tokens
normalization
distance_metric
```

Die Dimension muss Bestandteil der Konfiguration der jeweiligen Milvus Collection sein.

Ein Wechsel des Embedding-Modells darf nicht dazu führen, dass Vektoren inkompatibler Dimensionen in dieselbe Collection geschrieben werden.

Stattdessen:

```text
Embedding-Modell A
    ↓
Collection A

Embedding-Modell B
    ↓
Collection B
```

oder ein vergleichbar robustes Konzept.

Die UI muss klar anzeigen:

```text
Aktives Modell:
Qwen3-Embedding-0.6B

Dimension:
...

Collection:
...

Dokumente:
...

Vektoren:
...
```

---

# 6. Embedding-Service

Der Embedding-Service soll vollständig unabhängig vom PHP-Prozess arbeiten.

PHP darf nicht selbst den kompletten Modellprozess hosten.

Kommunikation beispielsweise über HTTP API innerhalb des Docker-Netzwerks.

Definiere eine klare interne API:

```text
GET  /health
GET  /model
POST /embed
POST /embed/batch
```

Beispiel:

```json
{
  "texts": [
    "Text 1",
    "Text 2"
  ]
}
```

Antwort:

```json
{
  "model": "Qwen3-Embedding-0.6B",
  "dimension": 1024,
  "embeddings": [
    [...]
  ]
}
```

Die tatsächlichen Werte müssen zur verwendeten Modellimplementierung passen.

Vor Inbetriebnahme muss durch Tests verifiziert werden:

* Modell lädt
* Modell antwortet
* Dimension stimmt
* Batch-Embedding funktioniert
* Fehler werden sauber gemeldet
* große Batches werden kontrolliert
* CPU/GPU-Nutzung ist nachvollziehbar

---

# 7. Dokumentenverarbeitung

Unterstütze mindestens:

```text
PDF
DOCX
PPTX
XLSX
TXT
MD
CSV
HTML
RTF
ODT
ODS
ODP
```

Architektur:

```text
Original
   ↓
Dateityp-Erkennung
   ↓
Document Converter
   ↓
normalisierter Text / Markdown
   ↓
Metadaten
   ↓
Chunking
   ↓
Embedding
   ↓
Milvus
```

Der Converter darf geeignete Open-Source-Werkzeuge verwenden.

Beispiele:

* LibreOffice headless
* pdftotext
* pandoc
* passende Parser

Keine externe Cloud-API.

---

# 8. Dokument-Metadaten

Für jedes Dokument speichern:

```text
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
```

Weitere sinnvolle Metadaten ergänzen.

---

# 9. Duplikaterkennung

Dokumente dürfen nicht blind mehrfach verarbeitet werden.

Verwende mindestens:

```text
SHA-256
```

über den Dateiinhalt.

Das System muss erkennen:

* neues Dokument
* unverändertes Dokument
* geändertes Dokument
* bereits verarbeitetes Dokument
* Dokument gelöscht/verschoben

Die UI soll den Status nachvollziehbar darstellen.

---

# 10. Ordnerauswahl

Im Webinterface muss der Benutzer einen lokalen Pfad angeben können.

Beispiel:

```text
/srv/documents
```

Wichtig:

Ein Browser darf aus Sicherheitsgründen nicht beliebig das lokale Dateisystem des Servers durchsuchen.

Deshalb soll die Anwendung serverseitig einen konfigurierten Root-Bereich anbieten.

Beispiel:

```text
/srv/data/input/
```

Der Benutzer kann darin einen Pfad auswählen.

Implementiere einen sicheren serverseitigen Directory Browser.

Anforderungen:

* keine Path Traversal Lücken
* keine Zugriffe außerhalb des erlaubten Root-Verzeichnisses
* Symlink-Prüfung
* Rechteprüfung
* verständliche Fehlermeldungen

---

# 11. Job-System

Ein Job muss mindestens enthalten:

```text
job_id
name
source_directory
recursive
embedding_model
embedding_dimension
created_at
started_at
finished_at
status
documents_total
documents_pending
documents_processing
documents_processed
documents_failed
chunks_total
vectors_total
tokens_total
bytes_total
error_count
```

Status:

```text
CREATED
SCANNING
QUEUED
PROCESSING
PAUSED
COMPLETED
COMPLETED_WITH_ERRORS
FAILED
CANCELLED
```

Jobs müssen persistent in MySQL gespeichert werden.

---

# 12. Jobs fortsetzen

Ein unterbrochener Job muss wiederaufgenommen werden können.

Beispielsweise nach:

* Container-Neustart
* Stromausfall
* Browser-Schließen
* PHP-Neustart
* Embedding-Service-Neustart

Bereits erfolgreich verarbeitete Dokumente dürfen nicht unnötig erneut verarbeitet werden.

Verwende idempotente Verarbeitung.

Ein Job muss im Webinterface beispielsweise folgende Aktionen anbieten:

```text
▶ Fortsetzen
⏸ Pausieren
■ Abbrechen
↻ Erneut prüfen
🗑 Löschen
```

---

# 13. Einzelupload

Zusätzlich zur Ordnerverarbeitung:

```text
Dokument hochladen
```

Unterstützte Dateitypen wie oben.

Upload:

```text
Browser
   ↓
PHP
   ↓
temporärer Speicher
   ↓
Dokumentenverarbeitung
   ↓
Chunking
   ↓
Embedding
   ↓
Milvus
```

Größenlimits konfigurieren.

Fehler sauber behandeln.

---

# 14. Chunking

Implementiere ein konfigurierbares Chunking-System.

Parameter beispielsweise:

```text
chunk_size
chunk_overlap
min_chunk_size
max_chunk_size
```

Bevorzugt tokenorientiert statt rein zeichenorientiert.

Die UI soll die Parameter anzeigen und konfigurierbar machen.

Chunks müssen Metadaten erhalten:

```text
document_id
chunk_id
chunk_index
page_start
page_end
text_length
token_count
```

---

# 15. Milvus

Definiere eine robuste Collection-Struktur.

Beispiel:

```text
id
document_id
job_id
chunk_index
embedding_model
embedding_dimension
source_path
page_start
page_end
text
metadata
vector
```

Prüfe, welche Felder sich für die konkrete Milvus-Version eignen.

Der Vektorindex muss zur verwendeten Dimension und Distanzmetrik passen.

Die konkrete Wahl des Indexes ist zu dokumentieren und durch Tests zu verifizieren.

---

# 16. Datenbankdesign

Erstelle ein nachvollziehbares relationales Schema.

Mindestens:

```text
settings
embedding_models
jobs
documents
document_chunks
processing_errors
system_metrics
audit_log
```

Zusätzliche Tabellen nach Bedarf.

Erstelle:

```text
database/schema.sql
database/migrations/
```

Migrationen müssen reproduzierbar sein.

---

# 17. REST/API

Das PHP-Backend soll eine saubere interne API besitzen.

Beispielsweise:

```text
GET    /api/health
GET    /api/system
GET    /api/settings
PUT    /api/settings

GET    /api/models
PUT    /api/models/active

GET    /api/jobs
POST   /api/jobs
GET    /api/jobs/{id}
POST   /api/jobs/{id}/start
POST   /api/jobs/{id}/pause
POST   /api/jobs/{id}/resume
POST   /api/jobs/{id}/cancel
DELETE /api/jobs/{id}

GET    /api/documents
GET    /api/documents/{id}

POST   /api/upload

GET    /api/statistics
GET    /api/storage

POST   /api/export
GET    /api/export/{id}
```

Kein Framework verwenden.

Routing sauber selbst implementieren.

---

# 18. Echtzeit-Fortschritt

Die UI muss live anzeigen:

```text
Fortschritt: 63 %

Dokumente:
Gesamt       12.482
Ausstehend    4.127
Verarbeitet   8.211
Fehler          144

Chunks:
123.442

Vektoren:
123.442

Tokens:
83.421.123

Embedding:
Qwen3-Embedding-4B

Geschwindigkeit:
18.432 Tokens/s
```

Technik:

* Server-Sent Events bevorzugt
* alternativ Polling
* WebSockets nur wenn architektonisch sinnvoll

Keine externe Infrastruktur.

---

# 19. Systemmetriken

Zeige live:

```text
CPU-Auslastung
RAM belegt
RAM frei
RAM gesamt
Load Average
Disk Usage
Disk Free
Disk Total
```

Zusätzlich sinnvoll:

```text
Embedding-Service Status
Milvus Status
MySQL Status
Converter Status
Worker Status
```

Die Werte müssen tatsächlich aus dem System stammen.

Keine simulierten Werte.

---

# 20. Dashboard

Erstelle ein modernes Dashboard.

Beispiel:

```text
┌─────────────────────────────────────────────────────────┐
│ Dokument Embedding Manager                              │
├────────────┬────────────┬────────────┬─────────────────┤
│ Dokumente  │ Vektoren   │ Speicher   │ Aktiver Job     │
│ 12.482     │ 123.442    │ 18.4 GB    │ 63 %            │
├────────────┴────────────┴────────────┴─────────────────┤
│                                                         │
│ Aktueller Job                                          │
│ ███████████████████████░░░░░ 63 %                     │
│                                                         │
│ 18.432 Tokens/s                                         │
│                                                         │
├─────────────────────────────────────────────────────────┤
│ System                                                  │
│ CPU █████████░░ 71 %                                    │
│ RAM ███████░░░ 64 %                                     │
│ Disk ██████░░░░ 57 %                                    │
└─────────────────────────────────────────────────────────┘
```

---

# 21. Historische Jobs

Eigene Ansicht:

```text
Jobs
```

Spalten:

```text
Job
Quelle
Modell
Dokumente
Chunks
Vektoren
Dauer
Tokens
Fehler
Status
Erstellt
```

Filter:

```text
Status
Modell
Datum
Quelle
```

Sortierung und Pagination.

---

# 22. Speicheranalyse

Eigene Ansicht:

```text
Speicher
```

Aufschlüsselung:

```text
Originaldokumente
Konvertierte Dokumente
Chunks
MySQL
Milvus
Docker Volumes
Exports
Logs
Temporäre Dateien
```

Grafische Darstellung.

---

# 23. Dokumentstatistiken

Bereitstellen:

```text
Dokumentanzahl
Seitenanzahl
Wörter
Zeichen
geschätzte Tokens
Chunks
Durchschnittliche Dokumentlänge
Median Dokumentlänge
kleinstes Dokument
größtes Dokument
```

Zeitliche Statistik:

```text
Dokumentalter
```

z.B.:

```text
< 1 Monat
1–6 Monate
6–12 Monate
1–3 Jahre
3–5 Jahre
> 5 Jahre
```

Zusätzlich:

```text
Dateitypen
Dokumente pro Verzeichnis
Dokumente pro Jahr
Seiten pro Dokument
Chunks pro Dokument
Tokens pro Dokument
```

---

# 24. Export

Implementiere eine Exportfunktion.

Ziel:

Ein vollständiger Vektordatenbestand soll auf ein anderes System übertragen werden können.

Export muss enthalten:

```text
Milvus-Daten
Collection-Konfiguration
Embedding-Modell
Embedding-Dimension
Metadaten
Schema
Versionsinformationen
```

Exportformat z.B.:

```text
.tar.zst
```

oder

```text
.tar.gz
```

Das Format muss offline und langfristig nutzbar sein.

Export muss reproduzierbar sein.

Beispiel:

```text
export_2026-09-30_185500.tar.zst
```

Manifest:

```json
{
  "application_version": "...",
  "created_at": "...",
  "embedding_model": "...",
  "dimension": 1024,
  "documents": 12482,
  "vectors": 123442,
  "checksum": "..."
}
```

Prüfe Exporte nach Erstellung automatisch.

---

# 25. Import

Wenn technisch sinnvoll, implementiere ebenfalls:

```text
Import
```

Damit ein Export auf einem anderen System wiederhergestellt werden kann.

Vor Import muss geprüft werden:

* Version
* Manifest
* Checksums
* Collection-Schema
* Embedding-Dimension
* Kompatibilität

---

# 26. HTTPS

Die Weboberfläche muss ausschließlich über HTTPS betrieben werden.

Verwende ein selbst signiertes Zertifikat.

Der gewünschte Workflow aus

```text
github.com/dareinelt/lanpa
```

soll als Referenz übernommen werden.

Wichtig:

Nicht einfach blind Code kopieren.

Analysiere zunächst den dort verwendeten CSR-/Sign-/Import-Workflow und übertrage das Konzept sauber in dieses Projekt.

Da das fertige System offline funktionieren muss, muss der Zertifikatsworkflow vollständig lokal funktionieren.

Unterstütze:

```text
Private Key
CSR
Certificate
CA / Signatur
Import
```

Die UI soll den Zertifikatsstatus anzeigen:

```text
HTTPS
✓ aktiv

Subject:
...

Issuer:
...

Gültig von:
...

Gültig bis:
...

Fingerprint:
...
```

---

# 27. Sicherheit

Besondere Aufmerksamkeit auf:

## PHP

* Prepared Statements
* keine SQL-Injection
* keine Command Injection
* sichere Dateioperationen
* sichere Uploads
* MIME-Prüfung
* Dateiendungsprüfung
* Größenlimits
* CSRF-Schutz
* Session-Sicherheit
* sichere Cookies

## Pfade

Schutz gegen:

```text
../
../../
Symlink escapes
absolute paths außerhalb des Root
```

## Docker

* keine unnötigen Ports
* internes Docker-Netzwerk
* Milvus nicht unnötig nach außen exponieren
* MySQL nicht unnötig nach außen exponieren
* Embedding-Service nicht unnötig nach außen exponieren

---

# 28. UI/UX

Die Oberfläche soll modern wirken.

Anforderungen:

* klare Navigation
* Sidebar
* Dashboard
* Statuskarten
* Tabellen
* Filter
* Suchfunktion
* Fortschrittsanzeigen
* Dialoge als Overlays
* Bestätigungsdialoge bei destruktiven Aktionen
* Toast-/Notification-System
* Ladeindikatoren
* verständliche Fehlermeldungen
* leere Zustände
* Skeleton Loading, wenn sinnvoll
* Tastaturbedienbarkeit
* sichtbarer Fokus
* sinnvolle ARIA-Attribute

Keine überladene Oberfläche.

---

# 29. Hauptnavigation

Vorschlag:

```text
Dashboard

Dokumente
  ├─ Dokumente
  ├─ Ordner scannen
  └─ Upload

Jobs
  ├─ Aktive Jobs
  └─ Historie

Vektordaten
  ├─ Collections
  ├─ Speicher
  └─ Export / Import

Statistik

System
  ├─ Embedding
  ├─ Konverter
  ├─ Datenbank
  ├─ Milvus
  ├─ HTTPS
  └─ Logs

Einstellungen
```

---

# 30. Fehlerbehandlung

Jeder Verarbeitungsschritt muss Fehler sauber behandeln.

Beispiel:

```text
Dokument
 ↓
Konvertierung FEHLER
 ↓
Dokumentstatus = FAILED
 ↓
Fehler speichern
 ↓
Job läuft mit anderen Dokumenten weiter
```

Die UI muss zeigen:

```text
Dokument konnte nicht verarbeitet werden.

Datei:
example.docx

Schritt:
DOCX → Markdown

Fehler:
...

Zeit:
...
```

Fehler dürfen nicht dazu führen, dass ein kompletter Batch unnötig abbricht.

---

# 31. Logging

Strukturiertes Logging.

Mindestens:

```text
timestamp
level
service
job_id
document_id
message
duration
```

Levels:

```text
DEBUG
INFO
WARNING
ERROR
CRITICAL
```

Keine sensiblen Daten unnötig loggen.

---

# 32. Performance

Die Architektur muss auch größere Dokumentmengen verarbeiten können.

Berücksichtige:

* Batch Embeddings
* Streaming
* Queueing
* kontrollierte Parallelität
* Backpressure
* Chunk-Größen
* Milvus Batch Inserts
* Datenbank-Batches
* Wiederaufnahme nach Fehlern

Vermeide:

```text
alle Dokumente gleichzeitig in RAM
```

---

# 33. Worker-System

Implementiere einen separaten Worker-Prozess.

Beispiel:

```text
worker
  ↓
Job Queue
  ↓
Dokument
  ↓
Converter
  ↓
Chunker
  ↓
Embedding
  ↓
Milvus
```

Der Worker soll:

* Jobs aus MySQL holen
* Locking verwenden
* parallele Worker berücksichtigen
* nach Absturz Jobs wieder aufnehmen können
* Fortschritt persistent speichern

---

# 34. Datenkonsistenz

Besonders wichtig:

Ein Dokument darf nicht als vollständig verarbeitet markiert werden, bevor seine Vektoren erfolgreich gespeichert wurden.

Transaktionslogik bzw. Statusmodell entwickeln.

Beispiel:

```text
DISCOVERED
↓
CONVERTING
↓
CONVERTED
↓
CHUNKING
↓
EMBEDDING
↓
VECTOR_INSERT
↓
COMPLETED
```

---

# 35. Tests

Erstelle automatisierte Tests.

Mindestens:

## Unit Tests

* Pfadvalidierung
* Dateityperkennung
* Hashing
* Chunking
* Metadaten
* Konfiguration
* Jobstatus
* Fortschrittsberechnung

## Integration Tests

* PHP ↔ MySQL
* PHP ↔ Milvus
* Worker ↔ Embedding
* Worker ↔ Converter
* kompletter Dokumentenworkflow

## End-to-End

```text
Dokument
↓
Scan
↓
Konvertierung
↓
Chunking
↓
Embedding
↓
Milvus
↓
Statistik
↓
Export
```

---

# 36. Verpflichtende Verifikation

Dies ist besonders wichtig.

Der Coding-Agent darf nicht nur Dateien erzeugen und anschließend behaupten, dass alles funktioniert.

Für jeden wesentlichen Schritt muss tatsächlich getestet werden.

Beispiel:

```text
Schritt 1:
Docker Compose startet
→ ausführen
→ Exit-Code prüfen
→ Container prüfen
→ Healthchecks prüfen
→ Ergebnis dokumentieren

Schritt 2:
MySQL
→ Verbindung testen
→ Schema installieren
→ Tabellen prüfen

Schritt 3:
Milvus
→ Verbindung testen
→ Collection erstellen
→ Testvektor schreiben
→ Testvektor lesen

Schritt 4:
Embedding
→ Modell laden
→ Testtext senden
→ Dimension prüfen

Schritt 5:
Converter
→ Test-PDF
→ Test-DOCX
→ Test-PPTX
→ Output prüfen

Schritt 6:
Kompletter Job
→ Testdatenbestand
→ Job starten
→ Fortschritt beobachten
→ Milvus prüfen

Schritt 7:
Resume
→ Worker während Job stoppen
→ Container neu starten
→ Job fortsetzen
→ prüfen, dass bereits fertige Dokumente nicht doppelt verarbeitet werden

Schritt 8:
Export
→ Export erzeugen
→ Archiv testen
→ Checksum prüfen

Schritt 9:
HTTPS
→ Browser/HTTP-Client
→ Zertifikat prüfen
→ HTTPS-Verbindung testen
```

Jeder Schritt muss tatsächlich durchgeführt werden.

---

# 37. Testdaten

Erzeuge einen reproduzierbaren Testdatensatz.

Beispielsweise:

```text
tests/fixtures/documents/
├── sample.pdf
├── sample.docx
├── sample.pptx
├── sample.txt
├── sample.md
├── sample.csv
├── sample.html
└── nested/
    └── nested-document.pdf
```

Die Testdokumente müssen klein sein.

Zusätzlich Tests mit:

* leerem Dokument
* beschädigtem Dokument
* sehr langem Dokument
* Sonderzeichen
* Umlauten
* Unicode
* langen Dateinamen
* Duplikaten

---

# 38. Browser-Tests

Wenn eine Browser-Testumgebung verfügbar ist, teste die wichtigsten User-Flows tatsächlich.

Mindestens:

```text
Login / Startseite
Dashboard
Ordner auswählen
Job erstellen
Job starten
Fortschritt
Job pausieren
Job fortsetzen
Upload
Dokumentdetails
Statistik
Export
Systemstatus
HTTPS
```

Screenshots dieser Zustände für die Dokumentation erstellen.

---

# 39. Dokumentation

Erstelle eine umfangreiche Dokumentation.

Verzeichnis:

```text
docs/
```

Mindestens:

```text
README.md
INSTALLATION.md
ARCHITECTURE.md
CONFIGURATION.md
USER_GUIDE.md
ADMIN_GUIDE.md
TROUBLESHOOTING.md
SECURITY.md
API.md
DATABASE.md
MILVUS.md
EMBEDDING.md
DOCUMENT_PROCESSING.md
JOBS.md
EXPORT_IMPORT.md
HTTPS.md
TESTING.md
OFFLINE_OPERATION.md
```

---

# 40. Ausführliche DIN-A4-Dokumentation

Zusätzlich zur technischen Markdown-Dokumentation muss ein professionelles Dokument erstellt werden:

```text
docs/manual.pdf
```

Format:

```text
DIN A4
```

Die PDF muss drucktauglich sein.

## Sehr wichtig

Tabellen, Diagramme, Screenshots und Grafiken dürfen niemals über den Seitenrand hinauslaufen oder abgeschnitten werden.

Jedes Bild muss vollständig innerhalb des bedruckbaren Bereichs liegen.

Bei großen Screenshots:

* proportional skalieren
* ggf. auf mehrere Seiten verteilen
* niemals horizontal abschneiden

Bei großen Tabellen:

* Spalten umbrechen
* Schriftgröße reduzieren
* Tabelle ggf. auf mehrere Seiten aufteilen
* Querformat nur dann verwenden, wenn sinnvoll
* keine abgeschnittenen Tabellen

Keine Inhalte außerhalb der A4-Seite.

---

# 41. PDF-Dokumentationsstruktur

Die PDF soll mindestens enthalten:

## Deckblatt

```text
Dokument Embedding Manager

Technische Dokumentation
Version X.Y.Z

Datum
```

## Inhaltsverzeichnis

## 1. Überblick

## 2. Systemarchitektur

Diagramm:

```text
Browser
 ↓
Web
 ↓
App
 ├── MySQL
 ├── Milvus
 ├── Embedding
 └── Converter
```

## 3. Installation

Screenshots:

* Docker
* Containerstatus
* Startvorgang

## 4. Erstkonfiguration

Screenshots:

* Dashboard
* Einstellungen
* Modellkonfiguration

## 5. Ordnerverarbeitung

Screenshots:

* Ordnerauswahl
* rekursiv
* Jobkonfiguration
* Start

## 6. Jobüberwachung

Screenshots:

* Fortschrittsanzeige
* Tokens/s
* CPU
* RAM
* Dokumentstatus

## 7. Dokumente

Screenshots:

* Dokumentliste
* Dokumentdetails
* Upload

## 8. Statistiken

Screenshots:

* Dokumentalter
* Dateitypen
* Seiten
* Tokens
* Speicher

## 9. Milvus

Screenshots:

* Collections
* Modell
* Dimension
* Vektoren

## 10. Export

Screenshots:

* Exportdialog
* Exportstatus
* Exportdatei

## 11. HTTPS

Screenshots:

* Zertifikat
* CSR
* Import
* HTTPS-Verbindung

## 12. Administration

## 13. Fehlerbehebung

## 14. Tests

Dokumentiere:

```text
Test
Erwartetes Ergebnis
Tatsächliches Ergebnis
Status
```

## 15. Performance

Dokumentiere Testwerte.

---

# 42. Screenshots

Die Screenshots müssen aus der tatsächlich laufenden Anwendung stammen.

Keine Mockups.

Keine nachträglich erfundenen UI-Screenshots.

Für jeden wichtigen Workflow mindestens ein Screenshot.

Screenshots müssen:

* gut lesbar sein
* sinnvolle Auflösung besitzen
* vollständig sichtbar sein
* auf A4 passen
* beschriftet sein
* fortlaufend nummeriert werden

Beispiel:

```text
Abbildung 12 – Jobfortschritt während der Embedding-Verarbeitung
```

---

# 43. Diagramme

Erstelle professionelle Diagramme für:

1. Systemarchitektur
2. Datenfluss
3. Jobverarbeitung
4. Dokumentenverarbeitung
5. Datenbankschema
6. Milvus-Struktur
7. Export-/Importprozess
8. HTTPS-/Zertifikatsworkflow

Diagramme müssen ebenfalls A4-kompatibel sein.

---

# 44. Dokumentationsqualität

Die Dokumentation muss einen neuen Administrator in die Lage versetzen, das System ohne weitere Hilfe aufzusetzen.

Jeder relevante Befehl muss angegeben werden.

Beispiel:

```bash
docker compose build
docker compose up -d
docker compose ps
docker compose logs -f app
```

Erkläre jeweils:

* Zweck
* erwartetes Ergebnis
* typische Fehler
* Lösung

---

# 45. Konfiguration

Erstelle:

```text
.env.example
```

Beispielsweise:

```text
MYSQL_DATABASE=
MYSQL_USER=
MYSQL_PASSWORD=

MILVUS_HOST=
MILVUS_PORT=

EMBEDDING_HOST=
EMBEDDING_PORT=

DOCUMENT_ROOT=

UPLOAD_MAX_SIZE=

HTTPS_PORT=
HTTP_PORT=
```

Keine echten Secrets committen.

---

# 46. Versionierung

Die Anwendung benötigt eine Version.

Beispiel:

```text
0.1.0
```

Version muss sichtbar sein:

* Webinterface
* API
* Docker
* Dokumentation
* Exportmanifest

---

# 47. Health Checks

Implementiere:

```text
/api/health
```

sowie Service Healthchecks.

Gesamtstatus:

```text
Web          ✓
PHP          ✓
MySQL        ✓
Milvus       ✓
Embedding    ✓
Converter    ✓
Worker       ✓
HTTPS        ✓
```

---

# 48. Graceful Shutdown

Worker müssen sauber beendet werden.

Bei Docker Stop:

```text
aktuelles Dokument fertigstellen
Status speichern
Job nicht als abgeschlossen markieren
Worker beenden
```

Nach Neustart:

```text
Job erkennen
Status prüfen
fortsetzen
```

---

# 49. Crash Recovery

Simuliere:

```text
Worker kill
Embedding-Service kill
Milvus restart
MySQL restart
Docker compose restart
```

Danach muss das System einen konsistenten Zustand herstellen.

Dokumentiere die Ergebnisse.

---

# 50. Performance-Test

Erstelle einen Testdatensatz mit beispielsweise:

```text
100 Dokumenten
1.000 Dokumenten
```

soweit die lokale Hardware dies zulässt.

Messe:

```text
Dokumente/s
Chunks/s
Tokens/s
Embeddings/s
Milvus insert/s
RAM
CPU
Disk
```

Keine künstlichen Benchmarks.

Nur tatsächlich gemessene Werte dokumentieren.

---

# 51. Abschlussprüfung

Am Ende muss ein vollständiger automatisierter oder reproduzierbarer Smoke-Test existieren.

Beispiel:

```bash
./tests/smoke-test.sh
```

Dieser muss prüfen:

```text
✓ Docker
✓ Web
✓ PHP
✓ MySQL
✓ Milvus
✓ Embedding
✓ Converter
✓ API
✓ Dokumentverarbeitung
✓ Vektorinsert
✓ Statistik
✓ Export
✓ HTTPS
```

---

# 52. Definition of Done

Das Projekt gilt nur dann als fertig, wenn ALLE folgenden Punkte erfüllt sind:

### Architektur

* [ ] Docker vorhanden
* [ ] App getrennt
* [ ] Web getrennt
* [ ] DB getrennt
* [ ] Milvus getrennt
* [ ] Embedding getrennt
* [ ] Converter getrennt
* [ ] Worker vorhanden

### Anwendung

* [ ] Dashboard
* [ ] Dokumentscan
* [ ] rekursiver Scan
* [ ] Upload
* [ ] Jobverwaltung
* [ ] Resume
* [ ] Pause
* [ ] Cancel
* [ ] Fortschritt
* [ ] Tokens/s
* [ ] CPU
* [ ] RAM
* [ ] Speicher
* [ ] Statistiken
* [ ] Export
* [ ] Import, sofern implementiert

### Embedding

* [ ] Qwen3 0.6B
* [ ] Qwen3 4B
* [ ] Qwen3 8B
* [ ] Modellwahl
* [ ] Dimensionsprüfung
* [ ] getrennte Collections/Kompatibilitätsprüfung

### Dokumente

* [ ] PDF
* [ ] DOCX
* [ ] PPTX
* [ ] XLSX
* [ ] TXT
* [ ] MD
* [ ] CSV
* [ ] HTML
* [ ] RTF
* [ ] ODT
* [ ] ODS
* [ ] ODP

### Sicherheit

* [ ] Path Traversal Schutz
* [ ] Upload-Schutz
* [ ] SQL-Injection-Schutz
* [ ] CSRF-Schutz
* [ ] sichere Sessions
* [ ] Docker Security

### HTTPS

* [ ] Self-signed
* [ ] CSR
* [ ] Sign
* [ ] Import
* [ ] Zertifikatsanzeige

### Offline

* [ ] keine CDN-Abhängigkeit
* [ ] keine Cloud-Abhängigkeit
* [ ] keine Runtime-WAN-Verbindung
* [ ] Offline-Test durchgeführt

### Dokumentation

* [ ] technische Markdown-Doku
* [ ] Benutzerhandbuch
* [ ] Admin-Handbuch
* [ ] API-Doku
* [ ] Troubleshooting
* [ ] Sicherheitsdoku
* [ ] DIN-A4-PDF
* [ ] Screenshots
* [ ] Diagramme
* [ ] alle Screenshots A4-kompatibel
* [ ] alle Tabellen A4-kompatibel

### Verifikation

* [ ] Unit Tests
* [ ] Integration Tests
* [ ] E2E Tests
* [ ] Crash Recovery
* [ ] Resume Test
* [ ] Export Test
* [ ] HTTPS Test
* [ ] Offline Test
* [ ] Smoke Test

---

# 53. Arbeitsweise des Coding-Agenten

Arbeite nicht nach dem Prinzip:

```text
Alles programmieren
→ am Ende testen
```

Sondern:

```text
Anforderung
 ↓
Implementierung
 ↓
Test
 ↓
Verifikation
 ↓
Dokumentation
 ↓
nächste Anforderung
```

Nach jedem größeren Meilenstein:

1. Build durchführen
2. Container starten
3. Healthchecks prüfen
4. Tests ausführen
5. Logs prüfen
6. Funktion manuell prüfen, wenn erforderlich
7. Ergebnis dokumentieren
8. erst danach weiterarbeiten

Wenn ein Test fehlschlägt:

```text
Fehler analysieren
↓
Ursache beheben
↓
Test erneut durchführen
↓
Regressionstest ergänzen
```

Nicht einfach weitermachen.

---

# 54. Umgang mit Unsicherheiten

Wenn eine technische Entscheidung nicht eindeutig ist:

1. nicht raten,
2. die konkrete installierte Version prüfen,
3. vorhandene Dokumentation/CLI/API prüfen,
4. einen minimalen Test durchführen,
5. Ergebnis dokumentieren.

Insbesondere gilt dies für:

* Qwen3-Embedding-Modellparameter
* Embedding-Dimensionen
* Milvus-Version
* Milvus-Indexparameter
* Converter-Verhalten
* Docker-Kompatibilität
* CSR-/Zertifikatsworkflow

---

# 55. Bestehendes GitHub-Projekt

Das Projekt

```text
github.com/dareinelt/lanpa
```

ist insbesondere hinsichtlich des CSR-/Sign-/Import-Workflows zu untersuchen.

Übernimm daraus nur die für dieses Projekt benötigte Funktionalität bzw. das technische Konzept.

Prüfe dabei:

* Lizenz
* Abhängigkeiten
* Sicherheitsaspekte
* Offline-Fähigkeit
* Kompatibilität
* notwendige Anpassungen

Dokumentiere die daraus übernommenen Konzepte.

Die fertige Anwendung darf keine Runtime-Abhängigkeit von diesem GitHub-Projekt besitzen.

---

# 56. Projektstruktur

Eine mögliche Zielstruktur:

```text
project/
├── app/
│   ├── public/
│   ├── src/
│   ├── config/
│   ├── migrations/
│   └── tests/
│
├── web/
│   ├── nginx.conf
│   └── ssl/
│
├── embedding/
│   ├── src/
│   ├── models/
│   └── tests/
│
├── converter/
│   ├── src/
│   └── tests/
│
├── worker/
│   ├── src/
│   └── tests/
│
├── database/
│   ├── schema.sql
│   └── migrations/
│
├── docker/
│   ├── app/
│   ├── web/
│   ├── embedding/
│   ├── converter/
│   └── worker/
│
├── tests/
│   ├── fixtures/
│   ├── integration/
│   ├── e2e/
│   └── smoke-test.sh
│
├── docs/
│   ├── README.md
│   ├── ARCHITECTURE.md
│   ├── ...
│   └── manual.pdf
│
├── scripts/
│
├── docker-compose.yml
├── .env.example
├── README.md
└── LICENSE
```

Die tatsächliche Struktur darf angepasst werden, wenn eine bessere Architektur begründet werden kann.

---

# 57. Finale Übergabe

Am Ende liefere:

1. vollständigen Sourcecode
2. Docker-Konfiguration
3. Datenbankschema
4. Migrationen
5. Tests
6. Testdaten
7. Smoke-Test
8. `.env.example`
9. README
10. technische Dokumentation
11. Benutzerhandbuch
12. Administratorhandbuch
13. API-Dokumentation
14. Security-Dokumentation
15. Offline-Dokumentation
16. DIN-A4-PDF
17. Screenshots
18. Architekturdiagramme
19. Testreport
20. Liste aller bekannten Einschränkungen

Zusätzlich einen abschließenden Bericht:

```text
IMPLEMENTIERT
=============
...

GETESTET
========
...

VERIFIZIERT
===========
...

BEKANNTE EINSCHRÄNKUNGEN
========================
...

NICHT IMPLEMENTIERT
===================
...

TESTERGEBNIS
============
PASS / FAIL
```

Keine Funktion als „fertig“ markieren, wenn sie nicht tatsächlich getestet wurde.

---

# 58. Wichtigste Priorität

Die Priorität ist:

1. Funktionale Korrektheit
2. Datenintegrität
3. Wiederaufnahmefähigkeit
4. Offline-Fähigkeit
5. Sicherheit
6. reproduzierbare Docker-Installation
7. Testbarkeit
8. Performance
9. Benutzerfreundlichkeit
10. Optik

Eine optisch schöne Oberfläche mit nicht zuverlässig funktionierender Dokumentverarbeitung ist nicht akzeptabel.

---

# 59. Start

Beginne zunächst mit einer kurzen technischen Analyse des Vorhabens.

Erstelle anschließend:

```text
1. Architekturentscheidung
2. Komponentenübersicht
3. Datenmodell
4. API-Entwurf
5. Docker-Entwurf
6. Teststrategie
7. Implementierungsplan
```

Danach implementiere Schritt für Schritt.

**Nicht alle Dateien auf einmal erzeugen.**

Nach jedem wesentlichen Schritt muss die lokale Docker-Umgebung verwendet werden, um die Implementierung tatsächlich zu verifizieren.

Beginne mit einem minimalen vertikalen End-to-End-Slice:

```text
Browser
 ↓
Web
 ↓
PHP
 ↓
MySQL
 ↓
Embedding-Service
 ↓
Milvus
```

Dieser Slice muss zunächst vollständig funktionieren.

Danach schrittweise erweitern:

```text
Dokument
 ↓
Converter
 ↓
Chunking
 ↓
Embedding
 ↓
Milvus
 ↓
Jobverwaltung
 ↓
Fortschritt
 ↓
Statistik
 ↓
Export
 ↓
HTTPS
 ↓
Dokumentation
```

Erst wenn ein Schritt verifiziert wurde, darf der nächste Schritt begonnen werden.
