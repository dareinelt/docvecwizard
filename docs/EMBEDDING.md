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
| GET | `/health` | Liveness; `status` (`ok`/`degraded`), `model_loaded`, `model`, `error` |
| GET | `/model` | Modellkatalog (`available` mit `downloaded`/`active`) und aktuell geladenes Modell (`active`, `active_info`) |
| POST | `/model/active` | Modell laden/aktivieren (`{"name": …}`); 409, wenn nicht heruntergeladen |
| POST | `/embed` | Texte einbetten (`texts`, optional `model`) |
| POST | `/embed/batch` | Batch einbetten (`texts`, optional `model`) |

Wird in `/embed`/`/embed/batch` ein `model` mitgegeben, das nicht dem geladenen
Modell entspricht, antwortet der Dienst mit **409** statt mit Vektoren der
falschen Dimension.

## Embedding-Mechanik

Für die Embedding-Berechnung wird das **letzte Token-Pooling** über
`last_hidden_state` und `attention_mask` verwendet (`_last_token_pool`). Die
Ausgabe wird anschließend L2-normalisiert.

```text
Text → Tokenizer → Modell (PyTorch) → last-token-pooling → L2-Norm → Vektor
```

## Modell-Download & -Verwaltung

Der laufende Dienst lädt **keine** Modelle herunter: der Container hängt nur am
internen Netz `backend` (ohne Internet) und lädt beim Start ausschließlich
`EMBEDDING_DEFAULT_MODEL`, sofern es unter `/models/<Name>/` (Host:
`embedding/models/<Name>/`) mit `config.json` und `*.safetensors` vorliegt.

Modelle werden mit dem Einmal-Werkzeug `model-download` bereitgestellt
(gleiches Image, Netz `tools` mit Internetzugang, führt
`embedding/scripts/download-models.py` aus):

```bash
# alle Modelle aus EMBEDDING_MODELS
docker compose --profile tools run --rm model-download
# gezielt ein weiteres Modell
docker compose --profile tools run --rm model-download python scripts/download-models.py Qwen3-Embedding-4B
```

`HF_HOME` zeigt auf `/models/.cache`. Fehlt das Standardmodell beim Start,
loggt der Dienst `model … is not present …` und meldet über `/health`
`"status": "degraded", "model_loaded": false, "error": …`.

Nur das **aktive** Modell wird in den Arbeitsspeicher geladen. Beim
Modellwechsel wird das alte Modell entladen (`_unload`) und das neue geladen.

## Aktives Modell: Dienst vs. Datenbank

Das aktive Modell ist zweimal bekannt: im Speicher des Dienstes (nach einem
Neustart wieder `EMBEDDING_DEFAULT_MODEL`) und als `embedding_models.active`
in der Datenbank (gesetzt über `POST /api/models/activate`). Die Datenbank ist
führend:

- Der Worker gleicht beim Start ab und lädt bei Abweichung das DB-aktive Modell
  in den Dienst.
- Bei der Dokumentverarbeitung sendet der Worker das Modell des Auftrags mit;
  antwortet der Dienst mit 409, lädt der Worker dieses Modell nach und
  wiederholt den Aufruf einmal.
- Die Suche sendet das DB-aktive Modell mit; bei 409 erhält der Nutzer eine
  klare Meldung statt eines stillen Dimensionsfehlers.
- Vor dem Milvus-Insert bzw. der Suche wird die Vektordimension gegen
  `embedding_models.dimension` geprüft.

## Konfiguration

Siehe [CONFIGURATION.md](CONFIGURATION.md), Abschnitt „Embedding-Service":

- `EMBEDDING_MODELS`
- `EMBEDDING_DEFAULT_MODEL`

## Modell-Synchronisation

`POST /api/models/sync` gleicht die Datenbanktabelle `embedding_models` mit
`catalog.json` ab. Dadurch werden neue Modelle registriert.

## Hinweise

- Der Download (Größe je Modell) kann mehrere Minuten dauern – Fortschritt im
  Terminal des `model-download`-Aufrufs.
- Für Offline-Betrieb müssen die Modelle einmalig heruntergeladen bzw. nach
  `embedding/models/` kopiert werden.
- Kleinere Modelle (0.6B) sind schneller, größere (8B) potenziell genauer.
