# Embedding-Service

## Übersicht

Der Embedding-Dienst erzeugt Vektorrepräsentationen von Texten mithilfe von
**Qwen3**-Embedding-Modellen. Er ist ein eigenständiger Python-Dienst
(FastAPI + PyTorch).

- **Image:** eigener Build (`embedding/`)
- **Port (intern):** `8000`
- **Modelle-Verzeichnis:** `/models` (Host: `embedding/models/`)
- **Ressourcen:** `mem_limit: 8g`, `cpus: 6`

## Modelle

Definiert in `embedding/catalog.json`:

| Name | Repo | Parameter | Dimension | Max. Input | Norm | Distanz |
| --- | --- | --- | --- | --- | --- | --- |
| Qwen3-Embedding-0.6B | Qwen/Qwen3-Embedding-0.6B | 0.6B | 1024 | 32768 | l2 | cosine |
| Qwen3-Embedding-4B | Qwen/Qwen3-Embedding-4B | 4B | 2560 | 32768 | l2 | cosine |
| Qwen3-Embedding-8B | Qwen/Qwen3-Embedding-8B | 8B | 4096 | 32768 | l2 | cosine |

Alle Modelle verwenden **L2-Normalisierung** und **Cosinus-Distanz**. Die
Dimension wird nie fest verdrahtet, sondern immer aus dem Katalog gelesen.

## API-Endpunkte

| Methode | Pfad | Beschreibung |
| --- | --- | --- |
| GET | `/health` | Liveness |
| GET | `/model` | Aktives Modell |
| POST | `/model/active` | Modell aktivieren |
| POST | `/embed` | Einzelnen Text einbetten |
| POST | `/embed/batch` | Batch einbetten |

## Embedding-Mechanik

Für die Embedding-Berechnung wird das **letzte Token-Pooling** über
`last_hidden_state` und `attention_mask` verwendet (`_last_token_pool`). Die
Ausgabe wird anschließend L2-normalisiert.

```text
Text → Tokenizer → Modell (PyTorch) → last-token-pooling → L2-Norm → Vektor
```

## Modell-Download & -Verwaltung

Beim Start lädt der Dienst die in `EMBEDDING_MODELS` konfigurierten Modelle
herunter (Hugging Face). `HF_HOME` zeigt auf `/models/.cache`.

Nur das **aktive** Modell wird in den GPU-/Arbeitsspeicher geladen. Beim
Modellwechsel wird das alte Modell entladen (`_unload`) und das neue geladen.

## Konfiguration

Siehe [CONFIGURATION.md](CONFIGURATION.md), Abschnitt „Embedding-Service":

- `EMBEDDING_MODELS`
- `EMBEDDING_DEFAULT_MODEL`

## Modell-Synchronisation

`POST /api/models/sync` gleicht die Datenbanktabelle `embedding_models` mit
`catalog.json` ab. Dadurch werden neue Modelle registriert.

## Hinweise

- Der erste Start lädt das Modell herunter (Größe je Modell). Das kann mehrere
  Minuten dauern – Fortschritt über `docker compose logs -f embedding`.
- Für Offline-Betrieb müssen die Modelle einmalig heruntergeladen werden.
- Kleinere Modelle (0.6B) sind schneller, größere (8B) potenziell genauer.
