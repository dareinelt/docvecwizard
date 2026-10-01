# REST-API-Referenz

## Basis

- **Basis-URL:** `https://localhost:8443`
- **Format:** JSON (Request und Response)
- **Anmeldung:** Alle Endpunkte außer den als *öffentlich* markierten erfordern
  eine angemeldete Sitzung (Cookie `docvec_sid`). Ohne Sitzung: `401`.
- **CSRF:** Alle schreibenden Endpunkte (`POST`, `PUT`, `DELETE`) – auch der
  Login – benötigen den Header `X-CSRF-Token`. Das Token liefern
  `GET /api/auth/me` bzw. `GET /api/csrf`; Login, Logout und Passwortänderung
  geben ein neues Token zurück. Ungültiges/fehlendes Token: `403`.

## Authentifizierung

| Methode | Pfad | Öffentlich | Beschreibung |
| --- | --- | --- | --- |
| GET | `/api/auth/me` | ja | Login-Status: `{authenticated, user, csrf_token}` |
| POST | `/api/auth/login` | ja | `{username, password}` → `{authenticated, user, csrf_token}`; `401` bei falschen Daten, `429` + `Retry-After` bei zu vielen Fehlversuchen |
| POST | `/api/auth/logout` | ja | Sitzung beenden → neues anonymes `csrf_token` |
| POST | `/api/auth/password` | nein | `{current_password, new_password}` (min. 12 Zeichen) |

Für `curl` wird ein Cookie-Jar benötigt (siehe Beispiele).

## Endpunkt-Übersicht

### Health & System

| Methode | Pfad | Beschreibung |
| --- | --- | --- |
| GET | `/healthz` | Liveness (öffentlich) |
| GET | `/api/health` | Gesamt-Health (DB, Embedding, Converter, Milvus) |
| GET | `/api/csrf` | CSRF-Token abrufen (öffentlich) |
| GET | `/api/system` | Systemstatus/-informationen |
| GET | `/api/metrics` | Systemmetriken |

### Einstellungen

| Methode | Pfad | Beschreibung |
| --- | --- | --- |
| GET | `/api/settings` | Alle Einstellungen lesen |
| PUT | `/api/settings` | Einstellungen ändern |

### Modelle

| Methode | Pfad | Beschreibung |
| --- | --- | --- |
| GET | `/api/models` | Alle Modelle auflisten |
| POST | `/api/models/activate` | Modell aktivieren |
| POST | `/api/models/sync` | Modelle aus Katalog synchronisieren |

### Aufträge (Jobs)

| Methode | Pfad | Beschreibung |
| --- | --- | --- |
| GET | `/api/jobs` | Jobs auflisten (`?limit=`) |
| POST | `/api/jobs` | Job anlegen |
| GET | `/api/jobs/{id}` | Job-Details |
| POST | `/api/jobs/{id}/cancel` | Job abbrechen |

### Dokumente

| Methode | Pfad | Beschreibung |
| --- | --- | --- |
| GET | `/api/documents` | Dokumente auflisten (`?limit=`, `?search=`) |
| GET | `/api/documents/{id}` | Dokument-Details (inkl. Chunks, Versionen, Quelle) |
| DELETE | `/api/documents/{id}` | Dokument löschen |
| POST | `/api/documents/{id}/retry` | Fehlgeschlagene aktuelle Version erneut verarbeiten (legt einen laufenden Ein-Dokument-Auftrag an; 202, 400 wenn nicht `FAILED` oder Quelldatei fehlt) |
| GET | `/api/documents/{id}/source` | Quell-Metadaten des Originals |
| GET | `/api/documents/{id}/versions` | Alle Versionen des Dokuments |
| GET | `/api/documents/{id}/chunks` | Chunks der aktuellen Version |
| GET | `/api/documents/{id}/vectors` | Vektoren der aktuellen Version |
| GET | `/api/documents/{id}/download` | Originaldatei als binärer Download |
| GET | `/api/documents/{id}/metadata` | Metadaten der aktuellen Version |

### Ordner & Upload

| Methode | Pfad | Beschreibung |
| --- | --- | --- |
| GET | `/api/browse` | Verzeichnis durchsuchen (`?path=`) |
| POST | `/api/upload` | Datei hochladen (multipart) |

### Statistiken

| Methode | Pfad | Beschreibung |
| --- | --- | --- |
| GET | `/api/statistics` | Gesamtstatistiken |
| GET | `/api/statistics/extensions` | Statistiken je Dateityp |
| GET | `/api/storage` | Speicheraufteilung (Originale, Base64, Vektoren) |
| GET | `/api/integrity` | Integritätsprüfung (Blobs, Hashes, Vektoren, Collections) |

### Quellen & Vektoren (Referenzen)

| Methode | Pfad | Beschreibung |
| --- | --- | --- |
| GET | `/api/vectors/{id}` | Vektor-Datensatz (MySQL) |
| GET | `/api/vectors/{id}/document` | Zugehöriges Dokument (Milvus → Original) |
| GET | `/api/chunks/{id}` | Chunk-Datensatz |
| GET | `/api/chunks/{id}/source` | Quelle eines Chunks |
| GET | `/api/source/{document_version_id}` | Quelle einer bestimmten Version |

### Kollektionen (Milvus)

| Methode | Pfad | Beschreibung |
| --- | --- | --- |
| GET | `/api/collections` | Collections auflisten |
| GET | `/api/collections/{name}/stats` | Collection-Statistiken |

### Export / Import

| Methode | Pfad | Beschreibung |
| --- | --- | --- |
| GET | `/api/exports` | Exporte auflisten |
| POST | `/api/exports` | Export anlegen |
| GET | `/api/exports/{id}/download` | Export herunterladen |
| POST | `/api/import` | Import ausführen (multipart `archive` + `strategy`, oder JSON `manifest`) |

### TLS / Zertifikate

| Methode | Pfad | Beschreibung |
| --- | --- | --- |
| GET | `/api/tls` | TLS-Status |
| POST | `/api/tls/csr` | CSR erzeugen |
| POST | `/api/tls/selfsigned` | Selbstsigniertes Zertifikat erzeugen |
| POST | `/api/tls/import` | Zertifikat importieren |
| POST | `/api/tls/{id}/activate` | Zertifikat aktivieren |

### Suche

| Methode | Pfad | Beschreibung |
| --- | --- | --- |
| POST | `/api/search` | Semantische Suche (`{query, limit}`, limit 1–50). Liefert nur Treffer aktueller Dokumentversionen; 409, wenn der Embedding-Dienst ein anderes als das aktive Modell geladen hat |

## Beispiele

### Health-Check

```bash
curl -sk https://localhost:8443/healthz
```

```json
{"status":"ok","time":"2025-01-01T12:00:00Z"}
```

### Anmelden

```bash
JAR=/tmp/jar
CSRF=$(curl -sk -c $JAR -b $JAR https://localhost:8443/api/auth/me | jq -r .csrf_token)
CSRF=$(curl -sk -c $JAR -b $JAR \
  -H "X-CSRF-Token: $CSRF" \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"<Passwort>"}' \
  https://localhost:8443/api/auth/login | jq -r .csrf_token)
curl -sk -b $JAR https://localhost:8443/api/health
```

### Job anlegen

```bash
curl -sk -b $JAR \
  -H "X-CSRF-Token: $CSRF" \
  -H "Content-Type: application/json" \
  -d '{"name":"Mein Auftrag","source_directory":"beispiele","recursive":true,"embedding_model":"Qwen3-Embedding-0.6B"}' \
  https://localhost:8443/api/jobs
```

### Semantische Suche

```bash
curl -sk -b $JAR \
  -H "X-CSRF-Token: $CSRF" \
  -H "Content-Type: application/json" \
  -d '{"query":"Was ist ein Dokument?","limit":10}' \
  https://localhost:8443/api/search
```

Treffer enthalten u. a. `document_id`, `filename`, `chunk_index`,
`page_start`/`page_end`, `text`, `distance` und `download_endpoint`.

## Fehlerformat

Fehler liefern einen HTTP-Statuscode und eine JSON-Antwort:

```json
{"error":"Beschreibung des Fehlers"}
```

| Status | Bedeutung |
| --- | --- |
| 400 | Ungültige Eingabe (Validierung, fehlerhaftes JSON) |
| 401 | Nicht angemeldet / falsche Zugangsdaten |
| 403 | CSRF-Token ungültig oder fehlend |
| 404 | Ressource oder Route nicht gefunden (auch bei ungültigen IDs) |
| 405 | Methode nicht erlaubt (Header `Allow`) |
| 409 | Konflikt (z. B. Datei existiert bereits) |
| 429 | Zu viele Anfragen/Fehlversuche (Header `Retry-After`) |
| 500 | Interner Fehler (Details nur im Server-Log) |
| 502 | Abhängiger Dienst (Milvus, Embedding, Converter) nicht erreichbar |

## Hinweise

- `POST /api/jobs` erfordert ein gültiges, bekanntes `embedding_model`
  (sonst `400`).
- `POST /api/search` erfordert ein aktives Modell und einen nicht-leeren
  `query` (max. 2000 Zeichen, `limit` 1–50; sonst `400`).
- `POST /api/upload` akzeptiert nur unterstützte Dokumenttypen bis
  `UPLOAD_MAX_SIZE` und überschreibt keine vorhandenen Dateien (`409`).
