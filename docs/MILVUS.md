# Milvus – Vektordatenbank

## Übersicht

Milvus ist die Vektordatenbank des Systems. Sie speichert die Embedding-Vektoren
und führt die Ähnlichkeitssuche aus.

- **Image:** `milvusdb/milvus:v2.5.27`
- **Modus:** `standalone` (eingebettetes etcd, lokaler Speicher)
- **Daten-Volume:** `milvus_data`
- **Ports (intern):** `19530` (gRPC/Proxy), `9091` (Metrics/`/healthz`)

## Collections

Für jedes Embedding-Modell wird eine eigene Collection angelegt:

```text
docvec_Qwen3-Embedding-0.6B
docvec_Qwen3-Embedding-4B
docvec_Qwen3-Embedding-8B
```

Die Collection wird über `MilvusClient::collectionFor($modelName)` benannt:
`docvec_<sanitized-model-name>`.

Der Grund: Jedes Modell hat eine eigene **Vektordimension**, und Milvus erlaubt
keine Mischung von Dimensionen innerhalb einer Collection.

| Modell | Dimension | Collection |
| --- | --- | --- |
| Qwen3-Embedding-0.6B | 1024 | `docvec_Qwen3-Embedding-0.6B` |
| Qwen3-Embedding-4B | 2560 | `docvec_Qwen3-Embedding-4B` |
| Qwen3-Embedding-8B | 4096 | `docvec_Qwen3-Embedding-8B` |

## Schema

Jede Collection hat folgende Felder:

| Feld | Typ | Beschreibung |
| --- | --- | --- |
| `document_id` | VarChar | UUID des Dokuments (Primärschlüssel) |
| `chunk_index` | Int64 | Chunk-Position |
| `vector` | FloatVector | Embedding-Vektor (dim je Modell) |

Index-Parameter:

```json
{
  "indexName": "vector_idx",
  "metricType": "COSINE",
  "indexType": "AUTOINDEX"
}
```

## Operationen

Der `MilvusClient` (`app/src/Services/MilvusClient.php`) implementiert:

| Methode | Funktion |
| --- | --- |
| `health()` | Liveness über `/healthz` |
| `createCollection()` | Collection mit Index anlegen |
| `listCollections()` | Collections auflisten |
| `hasCollection()` / `describeCollection()` | Collection prüfen/beschreiben |
| `collectionStats()` | Anzahl Vektoren |
| `insert()` | Vektoren einfügen |
| `flush()` | Daten persistieren |
| `search()` | Ähnlichkeitssuche |
| `deleteByFilter()` | Dokument löschen |
| `dropCollection()` | Collection löschen |

Die Kommunikation läuft über die Milvus-RESTful-v2-API (JSON), mit Retry-Logik
für transiente Fehler (`retriablePost`, max. 6 Versuche).

## Suche

`POST /api/search`:

1. Query wird vom Embedding-Service vektorisiert.
2. `search()` führt die Cosinus-Ähnlichkeitssuche aus.
3. Rückgabe: `document_id` + `chunk_index` + Distanz.

## Oberfläche

Die Ansicht **Kollektionen** listet alle Collections mit Modell, Dimension und
Vektoranzahl. `GET /api/collections` und `GET /api/collections/{name}/stats`.

## Betrieb

Milvus läuft ausschließlich im internen `backend`-Netzwerk. Ein direkter Zugriff
von außen ist nicht vorgesehen.

Logs:

```bash
docker compose logs milvus
```
