# Sicherheitskonzept

## Bedrohungsmodell

Die Anwendung ist für den Betrieb in einem **vertrauenswürdigen lokalen
Netzwerk** konzipiert und vollständig offlinefähig. Sie ist kein
mandantenfähiges Internet-Produkt.

## Netzwerk-Härtung

- Nur der `web`-Dienst veröffentlicht einen Port (`8443` → `443`).
- Alle Backend-Dienste (`db`, `milvus`, `embedding`, `converter`, `worker`)
  laufen im internen Docker-Netzwerk `backend` (`internal: true`) und sind von
  außen **nicht** erreichbar.
- Das `frontend`-Netzwerk verbindet nur `web` und `app`.

## TLS / HTTPS

- Der gesamte Verkehr zwischen Browser und Server läuft über HTTPS
  (TLS 1.2/1.3, nur AEAD-Cipher mit Forward Secrecy).
- Standardmäßig wird ein selbstsigniertes Zertifikat verwendet.
- Eigene Zertifikate können importiert oder per CSR beantragt werden
  (siehe [HTTPS.md](HTTPS.md)).
- Private Schlüssel werden mit AES-256-GCM verschlüsselt in der Datenbank
  abgelegt (`private_key_enc`, Schlüssel abgeleitet aus `SESSION_SECRET`) und
  erst bei Aktivierung atomar nach `web/ssl/` geschrieben (`key.pem` 0600,
  Verzeichnis 0700, Eigentümer `app`).
- CN/SAN werden gegen Allow-Lists validiert (kein Injizieren von
  OpenSSL-Konfigurationsdirektiven).

## Security-Header (nginx)

`web/security-headers.conf` setzt für alle Antworten:
`Content-Security-Policy` (nur `'self'`, keine Inline-Skripte/-Styles,
`frame-ancestors 'none'`), `Strict-Transport-Security`, `X-Frame-Options: DENY`,
`X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`,
`Permissions-Policy` und `Cross-Origin-Opener-Policy`. Downloads von
Originaldateien erhalten zusätzlich `Content-Security-Policy: sandbox`.

## Authentifizierung & Sitzungen

- **Login-Pflicht:** Alle `/api/*`-Endpunkte außer `/healthz`,
  `GET /api/auth/me`, `GET /api/csrf`, `POST /api/auth/login` und
  `POST /api/auth/logout` erfordern eine angemeldete Sitzung (sonst `401`).
- Passwörter werden mit `password_hash()` (**Argon2id**, Fallback Bcrypt)
  gespeichert; Mindestlänge 12 Zeichen. Der erste Administrator wird bei der
  Migration aus `ADMIN_USERNAME`/`ADMIN_PASSWORD` angelegt (nur wenn noch kein
  Konto existiert; ohne gültiges Passwort wird kein Konto angelegt und eine
  Warnung geloggt). Anlegen/Zurücksetzen:
  `docker compose exec app php bin/set-password.php admin`.
- **Brute-Force-Schutz:** fehlgeschlagene Logins werden je Benutzer und je IP
  gezählt (`LOGIN_MAX_ATTEMPTS_USER`, `LOGIN_MAX_ATTEMPTS_IP`,
  `LOGIN_THROTTLE_WINDOW`) → `429` mit `Retry-After`. Zusätzlich begrenzt nginx
  die API-Rate pro Client.
- **Sessions:** PHP-Sessions mit `use_strict_mode`, Cookie `docvec_sid` mit
  `HttpOnly`, `Secure`, `SameSite=Strict`; Session-ID-Regeneration bei Login
  und Passwortänderung; Leerlauf- (`SESSION_IDLE_TIMEOUT`) und absolutes
  Timeout (`SESSION_ABSOLUTE_TIMEOUT`). Session-Dateien liegen in
  `/app/storage/sessions` (0700).
- **CSRF:** Synchronizer-Token pro Session (256 Bit, `hash_equals`), Header
  `X-CSRF-Token` bei allen `POST`/`PUT`/`DELETE` – auch beim Login. Das Token
  wird bei Login/Passwortänderung rotiert.

## Eingabevalidierung & Path Traversal

- Pfade werden über `PathGuard` gegen die konfigurierte Wurzel
  (`INPUT_ROOT`/`STAGING_ROOT`) aufgelöst und abgesichert; Symlinks werden beim
  Durchsuchen und Scannen ignoriert.
- Uploads: Prüfung von Upload-Fehlercode, Dateiname, Größe und Dateiendung
  (nur unterstützte Dokumenttypen), kein Überschreiben vorhandener Dateien.
- Import-Archive: Begrenzung von Eintragsanzahl und entpackter Größe
  (`IMPORT_MAX_ENTRIES`, `IMPORT_MAX_BYTES`), Ablehnung von absoluten Pfaden
  und `..`.
- IDs (UUIDs), Limits, Einstellungen und Suchanfragen werden serverseitig
  validiert; ungültige Eingaben ergeben `400`/`404` statt `500`.
- Der Dokumentkonverter prüft ebenfalls gegen erlaubte Wurzeln
  (`_allowed_roots()`).
- SQL-Abfragen verwenden ausschließlich **Prepared Statements** (PDO, native
  Prepares), `LIKE`-Platzhalter werden maskiert.
- Interne Fehlermeldungen (Stacktraces, Upstream-Antworten) werden nur geloggt;
  der Client erhält eine generische Meldung.

## Secrets-Verwaltung

| Secret | Zweck | Schutz |
| --- | --- | --- |
| `MYSQL_PASSWORD` | DB-Zugang | nur `.env` (gitignored) |
| `MYSQL_ROOT_PASSWORD` | DB-Root | nur `.env` (gitignored) |
| `SESSION_SECRET` | Schlüsselableitung für TLS-Private-Keys (min. 32 Zeichen) | nur `.env` (gitignored) |
| `ADMIN_PASSWORD` | Initiales Administratorpasswort (nur beim ersten Start) | nur `.env`, danach leeren |

- `.env` ist in `.gitignore` ausgeschlossen.
- `.env.example` enthält ausschließlich Platzhalter; Platzhalter-Secrets
  (`change-me…`) werden von der Anwendung abgelehnt.
- **Achtung:** Wird `SESSION_SECRET` geändert, können bereits gespeicherte
  TLS-Schlüssel nicht mehr entschlüsselt werden (neu erzeugen/importieren).

## Audit

- Die Tabelle `audit_log` zeichnet sicherheitsrelevante Aktionen mit Zeitstempel,
  handelndem Benutzer und Details auf (Login/Logout/Fehlversuche,
  Passwortänderung, Uploads, Löschungen, Einstellungen, TLS-Änderungen).

## Empfehlungen für den Produktivbetrieb

1. Eigene Zertifikate (kein selbstsigniertes) verwenden.
2. Starkes Administratorpasswort setzen und `ADMIN_PASSWORD` danach aus `.env`
   entfernen.
3. Secrets regelmäßig rotieren.
4. Zugriff auf den Host auf vertrauenswürdige Netze beschränken.
5. Docker-Images regelmäßig aktualisieren (`docker compose pull`).
6. Betriebssystem- und Docker-Sicherheitsupdates einspielen.

## Bekannte Einschränkungen

- Ein Rollenmodell existiert nicht: alle Konten sind Administratoren.
- Selbstsignierte Zertifikate erzeugen Browser-Warnungen.
- Siehe auch [AUDIT_REPORT.md](AUDIT_REPORT.md).
