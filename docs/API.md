# REST-API-Referenz

## Basis

- **Basis-URL:** `https://localhost:8443`
- **Format:** JSON (Request und Response)
- **CSRF:** Alle schreibenden Endpunkte (`POST`, `PUT`, `DELETE`) benötigen den
  Header `x-csrf-token`. Das Token liefert `GET /api/csrf`.

## Authentifizierung

Sessions werden über Cookies verwaltet. Für die Nutzung über `curl` wird ein
Cookie-Jar benötigt. Das CSRF-Token wird vom Server signiert (CSRF-Secret).

## Endpunkt-Übersicht

### Health & System

| Methode | Pfad | Beschreibung |
| --- | --- | --- |
| GET | `/healthz` | nginx-Liveness |
| GET | `/api/health` | Gesamt-Health (DB, Embedding, Converter, Milvus) |
| GET | `/api/csrf` | CSRF-Token abrufen |
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
| POST | `/api/search` | Semantische Suche (`{query, limit}`) |

## Beispiele

### Health-Check

```bash
curl -sk https://localhost:8443/api/health
```

```json
{"status":"ok","checks":{"database":true,"embedding":true,"converter":true,"milvus":true}}
```

### Job anlegen

```bash
CSRF=$(curl -sk -c /tmp/jar https://localhost:8443/api/csrf | jq -r .token)
curl -sk -b /tmp/jar \
  -H "x-csrf-token: $CSRF" \
  -H "Content-Type: application/json" \
  -d '{"name":"Mein Auftrag","source_directory":"beispiele","recursive":true,"embedding_model":"Qwen3-Embedding-0.6B"}' \
  https://localhost:8443/api/jobs
```

### Semantische Suche

```bash
curl -sk -b /tmp/jar \
  -H "x-csrf-token: $CSRF" \
  -H "Content-Type: application/json" \
  -d '{"query":"Was ist ein Dokument?","limit":10}' \
  https://localhost:8443/api/search
```

## Fehlerformat

Fehler liefern einen HTTP-Statuscode und eine JSON-Antwort:

```json
{"error":"Beschreibung des Fehlers"}
```

## Hinweise

- `POST /api/jobs` erfordert ein gültiges, bekanntes `embedding_model`
  (sonst `400 Unknown embedding model`).
- `POST /api/search` erfordert ein aktives Modell und einen nicht-leeren
  `query` (sonst `400`).
