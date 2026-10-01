# Fehlerbehebung

## Diagnose-Grundbefehle

```bash
docker compose ps                          # Dienststatus
docker compose logs --tail=100 app        # Backend-Logs
docker compose logs --tail=100 worker     # Worker-Logs
curl -sk https://localhost:8443/api/health # Health-Check
```

## Häufige Probleme

### 1. Health-Check meldet `database: false`

**Ursache:** MariaDB nicht erreichbar oder Zugangsdaten falsch.

**Diagnose:**

```bash
docker compose logs db
docker compose exec db healthcheck.sh --connect --innodb_initialized
```

**Lösung:**
- Prüfen, ob `db` läuft und gesund ist.
- Bei geänderten Zugangsdaten: die `MARIADB_*`-Variablen wirken nur bei der
  **ersten** Initialisierung des Volumes. Entweder Datenbank-User manuell
  anpassen oder Volume zurücksetzen (`docker compose down -v`, **Datenverlust!**).

### 2. Worker startet nicht / crasht (`Access denied`)

**Symptom:** `SQLSTATE[HY000] [1045] Access denied for user ''@...`

**Ursache:** Der Stack wurde aus einem Verzeichnis **ohne gültige `.env`**
gestartet, sodass die Datenbank-Variablen leer waren.

**Lösung:** Sicherstellen, dass im Start-Verzeichnis eine korrekte `.env`
vorhanden ist, dann `docker compose up -d` erneut ausführen.

> **Wichtig:** Da alle Worktrees denselben Compose-Projektnamen (`docvecwizard`)
> verwenden, teilen sie sich dieselben Container/Volumes. Starten Sie `docker
> compose` **immer** aus dem Verzeichnis, das die gültige `.env` enthält.

### 3. `embedding: false` – Modell wird nicht geladen

**Diagnose:**

```bash
docker compose logs embedding
```

**Ursachen/Lösungen:**
- Modell nicht vorhanden (`model … is not present in /models/…` im Log,
  `/health` meldet `model_loaded: false`) → einmalig
  `docker compose --profile tools run --rm model-download` ausführen, dann
  `docker compose restart embedding`.
- Zu wenig RAM → kleineres Modell wählen oder `mem_limit` erhöhen.

### 4. `converter: false`

**Diagnose:**

```bash
docker compose logs converter
```

**Lösung:** `docker compose restart converter`.

### 5. `milvus: false`

**Diagnose:**

```bash
docker compose logs milvus
```

**Lösung:** Milvus braucht beim Start etwas Zeit (healthcheck `start_period: 40s`).
Bei dauerhaftem Fehler `docker compose restart milvus`.

### 6. Browser meldet Zertifikatswarnung

Das ist bei selbstsignierten Zertifikaten normal. Einmalig bestätigen oder ein
eigenes Zertifikat importieren (siehe [HTTPS.md](HTTPS.md)).

### 7. Dokument bleibt auf `PROCESSING` oder Job auf `RUNNING` hängen

**Ursache:** Der Worker wurde hart beendet (z. B. OOM, Crash).

**Lösung:** Der Worker führt beim Start automatisch `recoverStaleWork()` aus
und setzt hängende Einträge zurück. Einfach neu starten:

```bash
docker compose restart worker
```

### 8. „Source directory not found"

**Ursache:** Das Quellverzeichnis existiert nicht unter `data/input/`.

**Lösung:** Verzeichnis anlegen bzw. korrekten relativen Pfad verwenden.

### 9. Konvertierung schlägt fehl (`FAILED`)

**Diagnose:**

```bash
docker compose logs converter
# Fehlerdetails pro Dokument in der Oberfläche (Dokumentdetails) oder API
curl -sk -H 'x-csrf-token: …' https://localhost:8443/api/documents/<id>
```

**Ursache:** Nicht unterstütztes oder beschädigtes Dokument, Timeout.

### 10. Speicherplatz voll

```bash
docker system df
```

**Lösung:** Ungenutzte Images/Volumes bereinigen (`docker system prune`).
Achtung: `-v` löscht auch Volumes.

## Log-Level erhöhen

In `.env`:

```dotenv
LOG_LEVEL=DEBUG
```

Dann `docker compose up -d` erneut ausführen.
