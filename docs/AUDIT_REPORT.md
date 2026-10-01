# Audit Report – Document Embedding Manager (docvecwizard)

**Umfang:** PHP-8.3-REST-API (`app/`), Vanilla-JS-SPA (`web/html`), nginx
(`web/`), Docker-Compose-Infrastruktur, Datenbankschema/Migrationen.
Python-Dienste (`embedding/`, `converter/`) wurden nur an den Schnittstellen
betrachtet.

## Zusammenfassung

| | Status |
| --- | --- |
| **Vor dem Audit** | 🔴 **Critical Fixes Needed** |
| **Nach dem Refactoring** | 🟢 **Ready** (mit den unten genannten Rest-Empfehlungen) |

Die generierte Codebasis war funktional weit fortgeschritten (Prepared
Statements, `PathGuard`, `strict_types`, Retry-Logik), hatte aber
**keinerlei Authentifizierung**: jede Person mit Netzwerkzugriff konnte
Dokumente lesen/löschen, Dateien hochladen, Exporte (inkl. aller Originale)
herunterladen und das ausgelieferte TLS-Zertifikat austauschen. Dazu kamen
eine OpenSSL-Konfigurations-Injection, ein öffentlich ableitbarer
Verschlüsselungsschlüssel für TLS-Private-Keys, fehlende Security-Header und
mehrere Funktionsfehler (Uploads > 2 MB, Timeouts, verwaiste Vektoren,
SAN nicht im Zertifikat). Architektonisch war ein ~1500-Zeilen-„God
Controller“ das Hauptproblem.

Alle als *Critical* und *Medium* eingestuften Punkte wurden behoben und mit
Unit-Tests (69/69 grün), einem Live-Test gegen einen reduzierten Docker-Stack
(db + migrate + app + web) sowie einem jsdom-UI-Test verifiziert.

## Gefundene Probleme

### 1. Funktion & Lauffähigkeit

| # | Problem | Schwere | Behebung |
| --- | --- | --- | --- |
| F1 | PHP-Defaults `upload_max_filesize=2M`/`post_max_size=8M` – Uploads und Archiv-Importe > 2 MB schlugen still fehl; `UPLOAD_MAX_SIZE` wurde nirgends ausgewertet | Critical | `app/docker-entrypoint.sh` schreibt `zz-docvecwizard.ini` aus `UPLOAD_MAX_SIZE`/`POST_MAX_SIZE`; `UploadService` prüft Größe serverseitig |
| F2 | Self-Signed-Zertifikat/CSR: SAN wurde in die Config geschrieben, aber `x509_extensions`/`req_extensions` nie gesetzt → SAN fehlte im Zertifikat (Browser lehnen CN-only ab) | Critical | `TlsService::withCsrConfig` + `v3_req`; live verifiziert (`DNS:audit.local, IP:127.0.0.1`) |
| F3 | TLS-Aktivierung überschrieb root-eigene `cert.pem`/`key.pem` per `file_put_contents` → Permission denied; zudem nicht atomar (nginx konnte ein nicht passendes Paar laden) | Critical | Atomares Schreiben via `tempnam` + `rename`, Key zuerst |
| F4 | Milvus-Bereinigung beim Löschen lag als Business-Logik im Controller, lief nur bei `chunk_count > 0` und protokollierte Fehler nur per `error_log` | Low | In `DocumentService::delete` verschoben (UUID-geprüft, Best-Effort, strukturiertes Logging) |
| F5 | Erneute Verarbeitung (Retry/Crash-Recovery) erzeugte doppelte Chunks/Vektoren | Medium | `ProcessingService::processDocument` räumt vorhandene Chunks/Vektoren der Version vorher ab |
| F6 | Import mit Strategie `overwrite` ließ alte Vektoren stehen; `is_current` wurde nicht übernommen | Medium | `ExportService::applyImport/importVersion` korrigiert |
| F7 | nginx `fastcgi_read_timeout` 60 s → Export/Import/Integritätsprüfung brachen mit 504 ab | Medium | `fastcgi_read_timeout 600s`, `set_time_limit(0)` für Export |
| F8 | Export-Download lud Multi-GB-Archive komplett in den Speicher | Medium | `Response::file` streamt per `readfile` |
| F9 | Integritätsprüfung lud alle Blobs gleichzeitig (Memory-Exhaustion) | Medium | Keyset-Pagination über `blob_id` |
| F10 | Mehrere Worker-Container verarbeiteten dieselben Jobs parallel | Medium | Single-Instance-Lock (`GET_LOCK`) mit Hot-Standby |
| F11 | Ungültige IDs/Parameter führten zu `500` (Uncaught `TypeError`/`ValueError`); fehlerhaftes JSON wurde als leeres Array akzeptiert | Medium | `Controller::uuidParam/intParam` → 404, `Router` → 400 „Malformed JSON body“, zentrales Exception-Mapping in `public/index.php` |
| F12 | Suche: fehlende Collection → 500; `limit` unbegrenzt; Frontend zeigte nur die rohe `document_id` | Low | `SearchService` (Validierung, leere Treffer), UI zeigt Datei, Seiten, Textauszug, Download |
| F13 | Dokumentfilter: nach Re-Render verloren die Zeilen ihre Click-Handler | Low | Event-Delegation |
| F14 | `HEAD`-Requests ergaben 405 (Healthchecks) | Low | Router behandelt `HEAD` wie `GET` |
| F15 | CRLF-Zeilenenden (Windows-Checkout) brachen `docker-entrypoint.sh` | Low | `.gitattributes` (`eol=lf`) + `sed 's/\r$//'` im Dockerfile |
| F16 | `DirectoryBrowser`/`scanFiles` folgten Symlinks (Endlosschleifen, Ausbruch aus `INPUT_ROOT`) | Medium | Symlinks werden übersprungen |

### 2. Architektur & Code-Qualität

| # | Problem | Schwere | Behebung |
| --- | --- | --- | --- |
| A1 | `ApiController` (~600 Zeilen, 47 Handler) vereinte Routing, Validierung, Business-Logik, Upload, Suche, Hydration | Medium | Aufgeteilt in `Controllers/{Auth,System,Model,Job,Document,File,Export,Tls,Search}Controller` + `Routes.php`; Logik in `UploadService`, `SearchService`, `UserService` |
| A2 | Fehlerbehandlung: generisches `catch (Throwable)` mit `$e->getMessage()` an den Client; keine typisierten HTTP-Fehler | Medium | `HttpException` (Status + Header), `UpstreamException` (502), `InvalidArgumentException` (400); Details nur im Log |
| A3 | Magische Status-Strings (`'RUNNING'`, `'COMPLETED'` …) verstreut | Low | `Domain/JobStatus` (Backed Enum) |
| A4 | `$_FILES`-Array-Wildwuchs im Controller | Low | `Http/UploadedFile` (readonly Value Object, normalisiert Einzel-/Mehrfachuploads) |
| A5 | Regex-Validierungen mit `$` ohne `D`-Modifier (akzeptierten abschließendes `\n`, u. a. in `PathGuard::validateName`) | Medium | `D`-Modifier ergänzt (PathGuard, Uuid, UserService, Settings, TLS) |
| A6 | Logger-Singleton ohne Instanz-API; „bootstrap complete“ auf `info` bei jedem Request | Low | `LoggerInstance`, Level `debug` |
| A7 | Keine Tests für Sicherheitsfunktionen | Medium | `tests/unit/SecurityTest.php` (22 Tests), Smoke-Test mit Login/CSRF/Header-Prüfungen |

### 3. Sicherheit

| # | Problem | Schwere | Behebung |
| --- | --- | --- | --- |
| S1 | **Keine Authentifizierung** – alle Endpunkte (Löschen, Upload, Export aller Originale, TLS-Austausch, Einstellungen) anonym nutzbar | Critical | Admin-Login (`Auth`, `AccessGuard`): Argon2id, Session-Regeneration, Login-Throttling (Benutzer + IP, `429` + `Retry-After`), Timing-sichere Prüfung mit Dummy-Hash, Passwortrichtlinie, Passwortänderung, CLI `bin/set-password.php` |
| S2 | CSRF: Token = HMAC über einen vom Client gelieferten Cookie-Wert mit `SESSION_SECRET` (Default leer → Token für beliebige Cookie-Werte fälschbar); kein serverseitiger Zustand, keine Rotation, Cookies ohne `Secure`; `CSRF_SECRET` konfiguriert, aber ungenutzt | Medium | Synchronizer-Token pro Session (256 Bit, `hash_equals`), zentral im Router-Guard für **alle** unsicheren Methoden inkl. Login; Rotation bei Login/Passwortwechsel |
| S3 | OpenSSL-Config-Injection: CN/SAN ungefiltert in temporäre `openssl.cnf` (Zeilenumbruch → beliebige Direktiven, z. B. `.include`) | Critical | `TlsService::validateSubject` (Allow-List `DNS:`/`IP:`, `FILTER_VALIDATE_IP`, max. 50 Einträge, kein `\r\n,\\`) |
| S4 | TLS-Private-Keys mit Schlüssel aus `SESSION_SECRET` verschlüsselt, der leer/Platzhalter sein durfte → öffentlich bekannter Schlüssel | Critical | `Config::secret()` lehnt leere/kurze/`change-me`-Secrets ab (fail closed) |
| S5 | `web/ssl` mit `0777`, `key.pem` mit `0644` – privater Schlüssel les- und austauschbar | Critical | Verzeichnis `0700` (Eigentümer `app`), `key.pem` `0600`, `umask 077` bei Generierung |
| S6 | Keine Security-Header (CSP, HSTS, X-Frame-Options, nosniff, Referrer-Policy) | Medium | `web/security-headers.conf`, strikte CSP ohne `unsafe-inline` (alle Inline-Styles aus dem Frontend entfernt) |
| S7 | Download von Originalen mit `addslashes()` im `Content-Disposition` und ohne Sandbox → Header-Injection/Stored XSS über hochgeladenes HTML | Medium | RFC-6266-Dateiname (`filename*`), `Content-Security-Policy: default-src 'none'; sandbox`, `nosniff` |
| S8 | Upload ohne Endungs-/Größen-/Fehlerprüfung, überschrieb vorhandene Dateien, Dateinamen mit Steuerzeichen | Medium | `UploadService` (UPLOAD_ERR, `PathGuard::validateName`, Endungs-Allow-List, 409 bei Existenz, `0644`, Audit) |
| S9 | Import-Archiv ohne Limits/Pfadprüfung (Tar-Bomb, `../`-Pfade); UUIDs ungeprüft | Medium | `assertSafeArchive` (`IMPORT_MAX_ENTRIES`, `IMPORT_MAX_BYTES`, Pfadprüfung), UUID-Validierung |
| S10 | Session-Cookies ohne `HttpOnly`/`Secure`/`SameSite`, kein Strict Mode, keine Timeouts | Medium | `Session` (Strict Mode, `HttpOnly`, `Secure`, `SameSite=Strict`, Idle-/Absolut-Timeout, Session-Verzeichnis `0700`, neue ID nach Logout) |
| S11 | Interne Fehlermeldungen (SQL, Milvus-Antworten, Pfade) an den Client | Medium | Generische Meldungen für 500/502, Details im Log |
| S12 | `LIKE`-Suche ohne Escaping von `%`/`_` (Wildcard-DoS, falsche Treffer) | Low | Escaping in `DocumentService` |
| S13 | Einstellungen: beliebige Schlüssel/Werte/Mengen speicherbar | Low | `SettingsService::update` (Schlüssel-Regex, Skalare, max. 4096 Zeichen, max. 100 Schlüssel, Transaktion) |
| S14 | Schwache TLS-Cipher-Liste (`HIGH:!aNULL:!MD5` inkl. CBC/ohne PFS) | Low | Nur ECDHE + AEAD, Session-Tickets aus |
| S15 | Kein Rate-Limit auf der API | Low | nginx `limit_req` (20 r/s, Burst 100) |
| S16 | Audit-Log ohne handelnden Benutzer; Login-Ereignisse nicht protokolliert | Low | `Audit::record` ergänzt `actor`; Login/Logout/Fehlversuch/Passwortwechsel |
| S17 | `expose_php`/`display_errors` nicht explizit deaktiviert | Low | PHP-ini im Entrypoint |

**Positiv bereits vorhanden:** durchgehend PDO-Prepared-Statements (keine
SQL-Injection gefunden), `PathGuard` für Pfadauflösung, `esc()` für die meisten
Frontend-Ausgaben, `declare(strict_types=1)` in allen PHP-Dateien,
Backend-Netzwerk `internal: true`.

### 4. Usability & UI/UX

| # | Problem | Schwere | Behebung |
| --- | --- | --- | --- |
| U1 | Kein Login-Bildschirm/Abmelden (Folge von S1); abgelaufene Sitzung → kryptische Fehler | Critical | Login-Ansicht, Benutzeranzeige, Abmelden, automatische Rückkehr zum Login bei `401`, Passwort-ändern-Formular |
| U2 | Nicht responsiv: feste Sidebar, auf Mobilgeräten unbenutzbar | Medium | Media Query < 760 px, einklappbares Menü (`aria-expanded`) |
| U3 | Barrierefreiheit: Labels ohne `for`/`id`, klickbare `<tr>` nicht per Tastatur bedienbar, Modal ohne Fokusführung/Escape, Toasts nicht angekündigt, keine Skip-Link, kein sichtbarer Fokus | Medium | `label for`, `tabindex` + Enter/Space auf Zeilen, Modal mit `role=dialog`, `aria-modal`, Fokusfalle, Escape, Fokus-Rückgabe; `aria-live`-Regionen (Fehler `assertive`); Skip-Link; `:focus-visible`; `aria-current`; Tabs nach WAI-ARIA (Pfeiltasten) |
| U4 | Doppelte Submits möglich, kein Ladefeedback bei langen Aktionen | Medium | `busy()`-Helper (deaktiviert Button, `aria-busy`), Hinweise bei Export/Integrität |
| U5 | Destruktive Aktionen ohne Bestätigung (TLS-Aktivierung, Import „Überschreiben“, Job abbrechen) | Medium | Bestätigungsdialoge mit konkretem Objektnamen |
| U6 | Fehlermeldungen technisch/englisch (`HTTP 500`, Exceptions) | Low | Deutsche, verständliche Meldungen; generische Texte je Statuscode; „Erneut versuchen“-Button |
| U7 | Status-Badges zeigten Rohwerte (`COMPLETED`) | Low | Deutsche Bezeichnungen, Rohwert als `title` |
| U8 | Race Conditions beim Filtern (ältere Antwort überschrieb neuere) | Low | Sequenznummer verwirft veraltete Antworten |

## Verifikation

- `php -l` für alle PHP-Dateien: fehlerfrei.
- `php tests/unit/run.php` (php:8.3-cli-alpine): **69/69** Tests grün.
- `node --check web/html/assets/js/app.js`: fehlerfrei.
- Live-Test (reduzierter Stack `db + migrate + app + web`):
  Security-Header, Cookie-Flags (`Secure`, `HttpOnly`, `SameSite=Strict`),
  `401` anonym, `403` ohne CSRF, Login + Session-ID-Rotation, Throttling
  (`429` + `Retry-After` nach 3 Fehlversuchen), Validierung (Einstellungen,
  Pfad-Traversal, Upload-Endung/Duplikat), TLS Self-Signed mit SAN +
  Aktivierung (Dateirechte 0700/0600, nginx-Reload), SAN-Injection abgewiesen,
  Logout.
- jsdom-UI-Test: Login-Fehler/Erfolg, XSS-Escaping, Filter + Event-Delegation,
  Tastaturbedienung, Escape schließt Modal, Logout.

## Rest-Empfehlungen (nicht Teil dieses Refactorings)

- Rollenmodell (Leser/Administrator), falls mehrere Personen arbeiten.
- Lange Operationen (Export/Import/Integrität) als Hintergrund-Jobs im Worker
  statt synchron im Request.
- Statische Analyse (PHPStan Level 8) und Integrationstests in CI.
- Python-Dienste (`embedding`, `converter`) einem separaten Audit unterziehen.
