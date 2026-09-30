"""Embedding service for Qwen3-Embedding models.

Exposes a small HTTP API used by the PHP backend:

    GET  /health
    GET  /model
    POST /model/active
    POST /embed
    POST /embed/batch

Models are loaded from a local directory (offline). The model catalog is a
JSON file; the *dimension* is never hard-coded in the embedding code itself.
"""

from __future__ import annotations

import json
import os
import threading
import time
from pathlib import Path
from typing import Any, Optional

import numpy as np
import torch
import torch.nn.functional as F
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

MODELS_DIR = Path(os.environ.get("MODELS_DIR", "/models"))
CATALOG_PATH = Path(__file__).resolve().parent.parent / "catalog.json"


def load_catalog() -> list[dict[str, Any]]:
    with CATALOG_PATH.open("r", encoding="utf-8") as fh:
        return json.load(fh)


class ModelManager:
    """Lazily loads and caches a single embedding model at a time."""

    def __init__(self, catalog: list[dict[str, Any]]) -> None:
        self.catalog: dict[str, dict[str, Any]] = {m["name"]: m for m in catalog}
        self._lock = threading.RLock()
        self._loaded: Optional[dict[str, Any]] = None
        self._active_name: Optional[str] = None
        self._load_error: Optional[str] = None

    # -- discovery ---------------------------------------------------------
    def is_downloaded(self, name: str) -> bool:
        target = MODELS_DIR / name
        return (target / "config.json").exists() and any(
            target.glob("*.safetensors")
        )

    def describe(self) -> dict[str, Any]:
        available = []
        for entry in self.catalog.values():
            item = dict(entry)
            item["downloaded"] = self.is_downloaded(entry["name"])
            item["active"] = entry["name"] == self._active_name
            available.append(item)
        active = self._active_name
        return {
            "active": active,
            "active_info": self._loaded_info(),
            "available": available,
        }

    def _loaded_info(self) -> Optional[dict[str, Any]]:
        if self._loaded is None:
            return None
        info = dict(self.catalog[self._loaded["name"]])
        info["dimension"] = int(self._loaded["dimension"])
        return info

    # -- loading -----------------------------------------------------------
    def _local_path(self, name: str) -> Path:
        return MODELS_DIR / name

    def load(self, name: str) -> dict[str, Any]:
        if name not in self.catalog:
            raise KeyError(f"unknown model: {name}")
        with self._lock:
            if self._active_name == name and self._loaded is not None:
                return self._loaded_info()  # type: ignore[return-value]

            path = self._local_path(name)
            if not self.is_downloaded(name):
                raise FileNotFoundError(
                    f"model {name} is not downloaded at {path}. "
                    f"Run scripts/download-models.py as a deployment step."
                )

            # Free the previous model before loading the next one.
            self._unload()

            from transformers import AutoModel, AutoTokenizer  # local import

            tokenizer = AutoTokenizer.from_pretrained(
                str(path), trust_remote_code=False
            )
            model = AutoModel.from_pretrained(
                str(path), trust_remote_code=False, torch_dtype=torch.float32
            )
            model.eval()

            dimension = int(self.catalog[name]["dimension"])
            self._loaded = {
                "name": name,
                "model": model,
                "tokenizer": tokenizer,
                "dimension": dimension,
                "max_input_tokens": int(self.catalog[name]["max_input_tokens"]),
                "loaded_at": time.time(),
            }
            self._active_name = name
            self._load_error = None
            return self._loaded_info()  # type: ignore[return-value]

    def _unload(self) -> None:
        self._loaded = None
        self._active_name = None
        self._load_error = None
        if torch.cuda.is_available():
            torch.cuda.empty_cache()
        import gc

        gc.collect()

    def active(self) -> dict[str, Any]:
        with self._lock:
            if self._loaded is None:
                raise HTTPException(status_code=503, detail="no model loaded")
            return dict(self._loaded)

    def has_active(self) -> bool:
        with self._lock:
            return self._loaded is not None

    # -- inference ---------------------------------------------------------
    @staticmethod
    def _last_token_pool(last_hidden: torch.Tensor, attention_mask: torch.Tensor) -> torch.Tensor:
        left_padding = (attention_mask[:, -1].sum() == attention_mask.shape[0])
        if left_padding:
            return last_hidden[:, -1]
        sequence_lengths = attention_mask.sum(dim=1) - 1
        batch_size = last_hidden.shape[0]
        return last_hidden[
            torch.arange(batch_size, device=last_hidden.device), sequence_lengths
        ]

    def embed(self, texts: list[str], batch_size: int = 32) -> np.ndarray:
        model_ctx = self.active()
        model = model_ctx["model"]
        tokenizer = model_ctx["tokenizer"]
        max_tokens = model_ctx["max_input_tokens"]

        embeddings: list[np.ndarray] = []
        with torch.no_grad():
            for i in range(0, len(texts), batch_size):
                chunk = texts[i : i + batch_size]
                inputs = tokenizer(
                    chunk,
                    padding=True,
                    truncation=True,
                    max_length=max_tokens,
                    return_tensors="pt",
                )
                outputs = model(**inputs)
                pooled = self._last_token_pool(
                    outputs.last_hidden_state, inputs["attention_mask"]
                )
                normalized = F.normalize(pooled, p=2, dim=1)
                embeddings.append(normalized.cpu().numpy().astype("float32"))
        return np.vstack(embeddings) if embeddings else np.empty((0, 0), dtype="float32")


# ---------------------------------------------------------------------------
# App
# ---------------------------------------------------------------------------
app = FastAPI(title="embedding-service", version="0.1.0")
manager = ModelManager(load_catalog())

# Eagerly load the default model (if downloaded) at startup.
_default = os.environ.get("EMBEDDING_DEFAULT_MODEL", "").strip()
if _default and _default in manager.catalog and manager.is_downloaded(_default):
    try:
        manager.load(_default)
    except Exception as exc:  # noqa: BLE001 - startup best effort
        manager._load_error = str(exc)  # type: ignore[attr-defined]


class EmbedRequest(BaseModel):
    texts: list[str] = Field(..., min_length=1)
    batch_size: int = 32


class ModelActiveRequest(BaseModel):
    name: str


@app.get("/health")
def health() -> dict[str, Any]:
    active = manager._active_name
    return {
        "status": "ok",
        "model_loaded": manager.has_active(),
        "model": active,
        "error": manager._load_error,
    }


@app.get("/model")
def get_model() -> dict[str, Any]:
    return manager.describe()


@app.post("/model/active")
def set_active(req: ModelActiveRequest) -> dict[str, Any]:
    try:
        info = manager.load(req.name)
    except KeyError:
        raise HTTPException(status_code=404, detail=f"unknown model: {req.name}")
    except FileNotFoundError as exc:
        raise HTTPException(status_code=409, detail=str(exc))
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=500, detail=f"failed to load model: {exc}")
    return {"active": req.name, "info": info}


@app.post("/embed")
def embed(req: EmbedRequest) -> dict[str, Any]:
    return _embed(req)


@app.post("/embed/batch")
def embed_batch(req: EmbedRequest) -> dict[str, Any]:
    return _embed(req)


def _embed(req: EmbedRequest) -> dict[str, Any]:
    if not req.texts:
        raise HTTPException(status_code=400, detail="texts must not be empty")
    if req.batch_size < 1 or req.batch_size > 256:
        raise HTTPException(status_code=400, detail="batch_size must be in [1, 256]")
    try:
        vectors = manager.embed(req.texts, batch_size=req.batch_size)
    except HTTPException:
        raise
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=500, detail=f"embedding failed: {exc}")
    active = manager._active_name or ""
    dimension = int(vectors.shape[1]) if vectors.ndim == 2 and vectors.shape[0] else 0
    return {
        "model": active,
        "dimension": dimension,
        "embeddings": vectors.tolist(),
    }
