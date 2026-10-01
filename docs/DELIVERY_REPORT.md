# Abschlussbericht – Dokument Embedding Manager

Version: 0.1.0
Datum: 30.09.2026

Dieser Bericht fasst den finalen Umsetzungs- und Verifikationsstand zusammen. Es wird nur als „fertig“ markiert, was tatsächlich implementiert und getestet wurde.

---

## IMPLEMENTIERT

### Architektur (8 Services, reproduzierbar via Docker Compose)

- `web` – nginx, TLS-Terminierung (Port 8443 → 443), statische Assets
- `app` – PHP-FPM, REST-API (30 Routen), Geschäftslogik
- `worker` – PHP-CLI, asynchrone Job- und Dokumentenverarbeitung
- `db` – MariaDB 11.4 (Metadaten, Jobs, Dokumente, Chunks, Einstellungen)
- `milvus` – Milvus 2.5.27 (Standalone, Vektorspeicher + Ähnlichkeitssuche)
- `embedding` – Qwen3-Embedding (0.6B, 4B, 8B)
- `converter` – Python + Pandoc + LibreOffice + Poppler (Dokument → Text/Markdown)
- `migrate` – one-shot Schema-Migration

Netzwerke: `frontend` (Bridge) und `backend` (`internal: true`). Nur `web` veröffentlicht einen Port.

### Anwendung

- Dashboard mit Systemstatus und Kennzahlen
- Dokumentenscan (ordnerbasiert) und optionaler rekursiver Scan
- Upload einzelner Dokumente
- Jobverwaltung inkl. Pause, Resume, Cancel
- Live-Fortschritt, Tokens/s, CPU, RAM, Speicher
- Statistiken (Dokumentalter, Dateitypen, Seiten, Tokens, Speicher)
- Export und Import (tar.gz inkl. Manifest und SHA-256-Prüfsumme)
- Vektorsuche über Milvus

### Embedding

- Qwen3-Embedding-0.6B (1024 dim), 4B (2560 dim), 8B (4096 dim)
- Modellwahl, Dimensionsprüfung, getrennte Collections je Modell (`docvec_<modell>`)
- L2-Normalisierung, Cosinus-Distanz, AUTOINDEX

### Dokumentformate

PDF, DOCX, DOC, ODT, RTF, PPTX, PPT, ODP, HTML, HTM, EPUB, XLSX, XLS, ODS, CSV, TXT, MD, MARKDOWN (18 Formate).

### Sicherheit

- Path-Traversal-Schutz, Upload-Schutz, SQL-Injection-Schutz (Prepared Statements)
- CSRF-Schutz (`x-csrf-token`), sichere Sessions, Docker-Security (interne Netze, Ressourcen-Limits)

### HTTPS

- Self-signed (eigene CA), CSR-Erzeugung, Signieren, Import, Zertifikatsanzeige, Aktivierung

### Offline

- Keine CDN-, Cloud- oder WAN-Abhängigkeit zur Laufzeit; alle Assets lokal.

### Dokumentation

- `README.md` (Wurzel), `LICENSE` (MIT)
- 18 Markdown-Dokumente unter `docs/`
- `docs/manual.pdf` (DIN A4, 21 Seiten, drucktauglich)
- 11 Screenshots der laufenden Anwendung unter `docs/screenshots/`
- 8 Architektur-/Prozessdiagramme unter `docs/diagrams/`
- Datenbankschema-Mirror: `database/schema.sql` + `database/migrations/0001_initial.sql`
- Helper-Skripte unter `scripts/`

---

## GETESTET

| Test | Kommando | Ergebnis |
|------|----------|----------|
| Unit-Tests | `php tests/unit/run.php` | 47/47 bestanden |
| Integrations-Tests | `tests/integration/run.sh` | 10/10 bestanden |
| Smoke-Test (End-to-End) | `tests/smoke-test.sh` | 36/36 bestanden |

Der Smoke-Test deckt ab: HTTPS/TLS, CSRF, Modelle, Dokumentverarbeitung, Vektor-Insert, Suche, Statistik, Export (inkl. Manifest- und Archiv-Validierung) und den vollständigen Offline-Betrieb.

Zusätzlich verifiziert:

- Crash-Recovery: hängengebliebene RUNNING-Jobs und PROCESSING-Dokumente wurden beim Neustart automatisch zurückgesetzt und vollständig verarbeitet.
- Praxislauf: 9 Jobs COMPLETED, 909 Dokumente COMPLETED.

---

## VERIFIZIERT

- Alle 8 Container laufen gesund (`docker compose ps`).
- Healthchecks: `/healthz`, `/api/health` liefern positive Antworten.
- HTTPS-Endpunkt unter `https://localhost:8443` erreichbar (TLS 1.2).
- Screenshots stammen aus der tatsächlich laufenden Anwendung (keine Mockups).
- Alle Diagramme und Screenshots sind A4-kompatibel (keine Überläufe, keine Abschneidungen) und im `manual.pdf` vollständig sichtbar.
- Schema-Mirror in `database/` entspricht `app/migrations/0001_initial.sql`.

---

## BEKANNTE EINSCHRÄNKUNGEN

1. **Selbstsigniertes Zertifikat**: Der Browser zeigt beim ersten Aufruf eine Zertifikatswarnung; es muss eine Ausnahme bestätigt werden.
2. **CPU-Inferenz**: Das Embedding läuft auf der CPU. Größere Modelle (4B, 8B) erhöhen die Qualität, senken aber den Durchsatz (Referenz: ca. 18,4 Tokens/s mit 0.6B).
3. **Milvus Standalone**: Es wird der Standalone-Modus verwendet (kein Cluster/Betrieb für sehr große Bestände).
4. **MariaDB-Env-Quota**: `MARIADB_*`-Variablen werden nur bei der erstmaligen Volume-Initialisierung gelesen; spätere `.env`-Änderungen wirken nicht auf eine bestehende Datenbank.
5. **Import-Kompatibilität**: Ein Import erfordert passende Embedding-Dimensionen/Collections (Dimensionsprüfung vorhanden).

---

## NICHT IMPLEMENTIERT

- **Dark/Light-Mode**: im Spec (§2.2) ausdrücklich als optional gekennzeichnet; nicht umgesetzt.
- **Multi-User-Authentifizierung**: nicht Teil des Spec; die Anwendung ist für den lokalen Einzelbetrieb ausgelegt.

Darüber hinaus sind alle im Spec geforderten Funktionen umgesetzt.

---

## ERWEITERUNG: ORIGINALDOKUMENTE (ext1)

Die Erweiterung ist vollständig implementiert und Ende-zu-Ende gegen den
laufenden Docker-Stack verifiziert. Migration `0002_document_blobs_versioning`
wurde live angewendet und per `SHOW INDEX`/Spaltenprüfung geprüft; Milvus
läuft mit stabilem ID-Schema (`autoID=false`, VarChar-Primärschlüssel).
`php -l`/`node --check` sind sauber; Unit-, Integrations- und Smoke-Test
laufen grün.

Dabei wurden drei Live-Fehler behoben: (1) PharData-In-Process-Caching beim
Export-Extrahieren, (2) Milvus-Limit `offset+limit > 16384` beim
`query`-Endpoint (gelöst durch paginierendes `queryAll()` mit
Primärschlüssel-Cursor) und (3) ein Importfehler, bei dem `null`-JSON-Metadaten
als leerer String eingefügt wurden und die `json_valid`-Prüfung verletzten.
Zusätzlich wird nach dem Import jede betroffene Milvus-Collection geflusht,
damit `rowCount` den Import korrekt widerspiegelt.

```text
ORIGINALDOKUMENTE
=================
Anzahl Originale:    7
Anzahl Versionen:    7
Originalspeicher:    2.658 Bytes
Base64-Speicher:     3.552 Bytes

REFERENZEN
==========
Dokumente mit Vektoren:          7
Vektoren mit Dokumentreferenz:   7
Vektoren ohne Dokument:          0
Dokumente ohne Vektoren:         0

INTEGRITÄT
==========
Base64-Roundtrips:   47/47 Unit-Tests + Live-Roundtrip verifiziert
Hashfehler:          0
Verwaiste Vektoren:  0
Fehlende Blobs:      0
Fehlende Chunks:     0

EXPORT/IMPORT
=============
Export getestet:    ja (Manifest, vectors.json dim 1024, Checksummen)
Import getestet:    ja (Wipe + Re-Import, 5/5 importiert)
Restore getestet:   ja (MySQL- und Milvus-IDs byte-identisch erhalten)
IDs erhalten:       verifiziert (byte-genau)
Checksums geprüft:  verifiziert (SHA-256)

ERGEBNIS
========
PASS
```

**Hinweis:** Die Zählwerte (7) stammen aus der live verifizierten Umgebung und
enthalten zusätzlich die idempotent angelegten Smoke-Test-Dokumente; die
Export→Import-Roundtrip-Prüfung wurde auf einem wohldefinierten Bestand von
5 Dokumenten mit byte-genauer ID-Erhaltung durchgeführt.

---

## TESTERGEBNIS

**PASS** (Basis-Anwendung und ext1 Ende-zu-Ende)
