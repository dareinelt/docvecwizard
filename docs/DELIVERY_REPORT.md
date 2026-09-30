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
- Export und Import (tar.zst inkl. Manifest und SHA-256-Prüfsumme)
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
| Unit-Tests | `php tests/unit/run.php` | 37/37 bestanden |
| Integrations-Tests | `tests/integration/run.sh` | 10/10 bestanden |
| Smoke-Test (End-to-End) | `tests/smoke-test.sh` | 35/35 bestanden |

Der Smoke-Test deckt ab: HTTPS/TLS, CSRF, Modelle, Dokumentverarbeitung, Vektor-Insert, Suche, Statistik, Export (inkl. Manifest-Validierung) und den vollständigen Offline-Betrieb.

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

## TESTERGEBNIS

**PASS**
