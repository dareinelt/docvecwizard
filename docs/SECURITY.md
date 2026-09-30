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

- Der gesamte Verkehr zwischen Browser und Server läuft über HTTPS.
- Standardmäßig wird ein selbstsigniertes Zertifikat verwendet.
- Eigene Zertifikate können importiert oder per CSR beantragt werden
  (siehe [HTTPS.md](HTTPS.md)).
- Private Schlüssel werden verschlüsselt in der Datenbank abgelegt
  (`private_key_enc`) und erst bei Aktivierung in `web/ssl/` geschrieben.

## Authentifizierung & Sitzungen

- Sessions werden über `SESSION_SECRET` signiert.
- CSRF-Schutz über `CSRF_SECRET` und `x-csrf-token`-Header bei allen
  schreibenden Endpunkten.
- Beide Secrets müssen in `.env` auf zufällige, lange Werte gesetzt werden
  (`openssl rand -hex 32`).

## Eingabevalidierung & Path Traversal

- Pfade werden über `PathGuard` gegen die konfigurierte Wurzel
  (`INPUT_ROOT`/`STAGING_ROOT`) aufgelöst und abgesichert.
- Der Dokumentkonverter prüft ebenfalls gegen erlaubte Wurzeln
  (`_allowed_roots()`).
- SQL-Abfragen verwenden ausschließlich **Prepared Statements** (PDO), keine
  String-Interpolation.

## Secrets-Verwaltung

| Secret | Zweck | Schutz |
| --- | --- | --- |
| `MYSQL_PASSWORD` | DB-Zugang | nur `.env` (gitignored) |
| `MYSQL_ROOT_PASSWORD` | DB-Root | nur `.env` (gitignored) |
| `SESSION_SECRET` | Session-Signatur | nur `.env` (gitignored) |
| `CSRF_SECRET` | CSRF-Token | nur `.env` (gitignored) |

- `.env` ist in `.gitignore` ausgeschlossen.
- `.env.example` enthält ausschließlich Platzhalter.

## Audit

- Die Tabelle `audit_log` zeichnet sicherheitsrelevante Aktionen mit Zeitstempel
  und Details auf (z. B. TLS-Änderungen).

## Empfehlungen für den Produktivbetrieb

1. Eigene Zertifikate (kein selbstsigniertes) verwenden.
2. Secrets regelmäßig rotieren.
3. Zugriff auf den Host auf vertrauenswürdige Netze beschränken.
4. Docker-Images regelmäßig aktualisieren (`docker compose pull`).
5. Betriebssystem- und Docker-Sicherheitsupdates einspielen.
6. Bei Exposition ins Internet zusätzlich einen Reverse-Proxy mit
   Authentifizierung vorschalten.

## Bekannte Einschränkungen

- Keine Benutzerkonten/Rollen (Einbenutzer-Betrieb).
- Selbstsignierte Zertifikate erzeugen Browser-Warnungen.
- Siehe auch Abschnitt „BEKANNTE EINSCHRÄNKUNGEN" im Abschlussbericht.
