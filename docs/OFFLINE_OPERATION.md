# Offline-Betrieb

## Grundsatz

Die Anwendung ist **vollständig offlinefähig**. Nach dem erstmaligen Aufsetzen
benötigt der Betrieb **keine** Internetverbindung.

## Was Internet benötigt (einmalig)

| Schritt | Zweck |
| --- | --- |
| `docker compose build` | Basis-Images herunterladen |
| Erster Start des `embedding`-Dienstes | Qwen3-Modelle aus Hugging Face laden |
| Erster Pull von `milvusdb/milvus` / `mariadb` | Basis-Images |

Nach diesen Schritten sind alle Artefakte lokal vorhanden:

- Docker-Images (lokal im Image-Cache)
- Embedding-Modelle (`embedding/models/`)
- Keine Laufzeit-Abhängigkeit von externen Diensten

## Verifikation des Offline-Betriebs

1. Netzwerkverbindung des Hosts trennen (oder Docker ohne Internet starten).
2. Stack starten:

```bash
docker compose up -d
```

3. Health prüfen:

```bash
curl -sk https://localhost:8443/api/health
```

**Erwartetes Ergebnis:** `{"status":"ok", ...}` mit allen Checks `true`.

## Vorbereitung für ein vollständig isoliertes System

### 1. Images lokal sichern

```bash
docker save mariadb:11.4 milvusdb/milvus:v2.5.27 \
  docvecwizard-app docvecwizard-web \
  docvecwizard-embedding docvecwizard-converter | gzip > images.tar.gz
```

### 2. Modelle sichern

Das Verzeichnis `embedding/models/` (inkl. Cache) sichern:

```bash
tar czf models.tar.gz -C embedding models
```

### 3. Wiederherstellung auf Offline-Maschine

```bash
docker load < images.tar.gz
tar xzf models.tar.gz -C embedding
cp .env.example .env   # Secrets setzen
docker compose up -d
```

## Einschränkungen im Offline-Betrieb

- **Kein** Modell-Download/Update möglich (Modelle müssen vorab vorliegen).
- **Kein** Update der Docker-Basis-Images möglich.
- Alles andere (Indizierung, Suche, Export, TLS-Selbstsignierung) funktioniert
  vollständig lokal.

## Docker-Daemon ohne Internet

Falls der Docker-Daemon beim Start versucht, fehlende Images zu pullen, die
Images vorab laden (siehe oben). Compose pullt nur fehlende Images – sind alle
lokal vorhanden, findet kein Netzwerkzugriff statt.
