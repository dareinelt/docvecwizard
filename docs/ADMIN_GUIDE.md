# Administratorhandbuch

## Täglicher Betrieb

### Status prüfen

```bash
docker compose ps
```

**Erwartetes Ergebnis:** Alle Dienste `running`, `migrate` `exited (0)`.

Gesundheitsstatus:

```bash
curl -sk https://localhost:8443/api/health
```

### Logs ansehen

```bash
# Alle Dienste
docker compose logs -f

# Nur Backend
docker compose logs -f app

# Nur Worker (Job-Verarbeitung)
docker compose logs -f worker

# Nur Embedding (Modell-Laden)
docker compose logs -f embedding
```

### Neustart

```bash
docker compose restart app worker
```

## Modelle verwalten

Die verfügbaren Modelle sind in `embedding/catalog.json` definiert. In der
Oberfläche unter **Einstellungen** bzw. über die API:

| Aktion | Endpoint |
| --- | --- |
| Modelle anzeigen | `GET /api/models` |
| Modell aktivieren | `POST /api/models/activate` |
| Modelle synchronisieren | `POST /api/models/sync` |

Ein Modellwechsel erzeugt eine neue Milvus-Collection (`docvec_<modellname>`),
da sich die Vektordimension unterscheidet.

## TLS / Zertifikate

Selbstsigniertes Zertifikat, CSR oder Zertifikatsimport über die Oberfläche
(**TLS-Zertifikat**) oder die API. Details in [HTTPS.md](HTTPS.md).

Nach einer Zertifikatsänderung wird das Zertifikat nach `web/ssl/` geschrieben
und der `web`-Dienst muss neu geladen werden:

```bash
docker compose restart web
```

## Datensicherung

### Datenbank (MariaDB)

```bash
docker compose exec db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" docvec' > backup.sql
```

### Vektordatenbank (Milvus)

Milvus-Daten liegen im Volume `milvus_data`. Sichern Sie das Volume:

```bash
docker run --rm -v docvecwizard_milvus_data:/data -v "$PWD":/backup alpine \
  tar czf /backup/milvus-backup.tar.gz -C /data .
```

### Dokumente

Die Quelldokumente liegen unter `data/input/`. Diese per Backup-Tool der Wahl
sichern.

## Wiederherstellung

```bash
# Datenbank
docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" docvec' < backup.sql
```

## Wartung

### Alte Exporte bereinigen

Exporte liegen unter `data/exports/`. Nicht mehr benötigte Dateien können
entfernt werden.

### Logs rotieren

Die Container-Logs werden von Docker verwaltet. Konfiguration über Docker
Daemon (json-file Log-Treiber).

## Ressourcen

Der Embedding-Dienst benötigt am meisten Ressourcen (`mem_limit: 8g`, `cpus: 6`).
Bei Ressourcenproblemen:

- kleineres Modell verwenden (`Qwen3-Embedding-0.6B`)
- `EMBEDDING_MODELS` auf ein einzelnes Modell begrenzen

## Sicherheitsrelevante Aufgaben

- Passwörter und Secrets regelmäßig rotieren (siehe [CONFIGURATION.md](CONFIGURATION.md))
- Zertifikatsablauf überwachen
- Siehe [SECURITY.md](SECURITY.md) für das vollständige Sicherheitskonzept

## Fehlerbehebung

Siehe [TROUBLESHOOTING.md](TROUBLESHOOTING.md).
