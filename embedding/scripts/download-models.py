#!/usr/bin/env python3
"""Download embedding models into /models as a *deployment* step.

The running `embedding` container has no internet access, therefore models
must be provided up front. Recommended invocation (same image, but on a
network with internet access):

    docker compose --profile tools run --rm model-download

Manual usage (inside the image or on a host with huggingface_hub installed):

    python scripts/download-models.py Qwen3-Embedding-0.6B Qwen3-Embedding-4B

If no argument is given, every model listed in EMBEDDING_MODELS (comma
separated) is downloaded. After that the stack works fully offline.
"""

from __future__ import annotations

import json
import os
import sys
from pathlib import Path

MODELS_DIR = Path(os.environ.get("MODELS_DIR", "/models"))
CATALOG_PATH = Path(__file__).resolve().parent.parent / "catalog.json"


def main() -> int:
    catalog = {m["name"]: m for m in json.loads(CATALOG_PATH.read_text())}
    requested = sys.argv[1:]
    if not requested:
        requested = [
            x.strip()
            for x in os.environ.get("EMBEDDING_MODELS", "Qwen3-Embedding-0.6B").split(",")
            if x.strip()
        ]

    from huggingface_hub import snapshot_download

    ok = True
    for name in requested:
        if name not in catalog:
            print(f"SKIP  {name}: unknown model", file=sys.stderr)
            ok = False
            continue
        target = MODELS_DIR / name
        if (target / "config.json").exists() and any(target.glob("*.safetensors")):
            print(f"SKIP  {name}: already downloaded at {target}")
            continue
        print(f"GET   {name} -> {catalog[name]['repo']} -> {target}")
        snapshot_download(
            repo_id=catalog[name]["repo"],
            local_dir=str(target),
        )
        print(f"DONE  {name}")

    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
