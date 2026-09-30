"""Document converter service.

Converts a local document into normalized text/markdown plus metadata.

    GET  /health
    POST /convert

The converter only ever reads files below the configured input/staging roots
(defense in depth; the PHP backend validates paths first).
"""

from __future__ import annotations

import os
import re
import shutil
import subprocess
import tempfile
from pathlib import Path
from typing import Any

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

INPUT_ROOT = Path(os.environ.get("INPUT_ROOT", "/srv/data/input"))
STAGING_ROOT = Path(os.environ.get("STAGING_ROOT", "/srv/data/staging"))
TIMEOUT = int(os.environ.get("CONVERT_TIMEOUT", "300"))

# Pandoc input formats we use directly.
PANDOC_FORMATS = {
    ".docx": "docx",
    ".pptx": "pptx",
    ".rtf": "rtf",
    ".odt": "odt",
    ".html": "html",
    ".htm": "html",
    ".epub": "epub",
    ".md": "markdown",
    ".markdown": "markdown",
}

# Formats converted via LibreOffice headless.
LIBREOFFICE_FORMATS = {".xlsx", ".ods", ".odp", ".ppt"}

# Formats read as plain text.
PLAIN_FORMATS = {".txt", ".md", ".markdown", ".csv"}

app = FastAPI(title="document-converter", version="0.1.0")


class ConvertRequest(BaseModel):
    path: str


def _allowed_roots() -> list[Path]:
    return [INPUT_ROOT, STAGING_ROOT]


def resolve_input(raw: str) -> Path:
    """Resolve and validate a requested path so it stays inside allowed roots."""
    candidate = Path(raw)
    if not candidate.is_absolute():
        candidate = INPUT_ROOT / candidate
    try:
        real = candidate.resolve(strict=True)
    except OSError:
        raise HTTPException(status_code=404, detail=f"path not found: {raw}")
    for root in _allowed_roots():
        try:
            real.relative_to(root.resolve())
            if not real.is_file():
                raise HTTPException(status_code=400, detail=f"not a file: {raw}")
            return real
        except ValueError:
            continue
    raise HTTPException(status_code=403, detail="path outside allowed roots")


def _run(cmd: list[str], timeout: int = TIMEOUT) -> subprocess.CompletedProcess:
    try:
        return subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=timeout,
            check=False,
        )
    except subprocess.TimeoutExpired:
        raise HTTPException(status_code=504, detail="conversion timed out")


def _pandoc(path: Path, fmt: str) -> str:
    proc = _run(["pandoc", "-f", fmt, "-t", "markdown", str(path)])
    if proc.returncode != 0:
        raise HTTPException(status_code=422, detail=f"pandoc failed: {proc.stderr[:300]}")
    return proc.stdout


def _libreoffice(path: Path, out_fmt: str) -> str:
    with tempfile.TemporaryDirectory() as tmp:
        proc = _run(
            [
                "libreoffice", "--headless", "--norestore",
                f"--convert-to", out_fmt,
                "--outdir", tmp, str(path),
            ],
            timeout=TIMEOUT,
        )
        if proc.returncode != 0:
            raise HTTPException(status_code=422, detail=f"libreoffice failed: {proc.stderr[:300]}")
        produced = list(Path(tmp).glob(f"*.{out_fmt}"))
        if not produced:
            raise HTTPException(status_code=422, detail="libreoffice produced no output")
        return produced[0].read_text(encoding="utf-8", errors="replace")


def _pdftotext(path: Path) -> tuple[str, int]:
    proc = _run(["pdftotext", "-layout", "-enc", "UTF-8", str(path), "-"])
    if proc.returncode != 0:
        raise HTTPException(status_code=422, detail=f"pdftotext failed: {proc.stderr[:300]}")
    text = proc.stdout
    # Page count via pdfinfo (best effort).
    pages = 0
    info = _run(["pdfinfo", str(path)])
    if info.returncode == 0:
        m = re.search(r"^Pages:\s+(\d+)", info.stdout, re.M)
        if m:
            pages = int(m.group(1))
    return text, pages


def _word_count(text: str) -> int:
    return len(re.findall(r"\S+", text))


@app.get("/health")
def health() -> dict[str, Any]:
    return {"status": "ok", "pandoc": bool(shutil.which("pandoc")),
            "libreoffice": bool(shutil.which("libreoffice")),
            "pdftotext": bool(shutil.which("pdftotext"))}


@app.post("/convert")
def convert(req: ConvertRequest) -> dict[str, Any]:
    path = resolve_input(req.path)
    ext = path.suffix.lower()

    if ext == ".pdf":
        text, pages = _pdftotext(path)
    elif ext in PANDOC_FORMATS:
        text = _pandoc(path, PANDOC_FORMATS[ext])
        pages = 0
    elif ext in LIBREOFFICE_FORMATS:
        text = _libreoffice(path, "csv" if ext in {".xlsx", ".ods"} else "txt")
        pages = 0
    elif ext in PLAIN_FORMATS:
        text = path.read_text(encoding="utf-8", errors="replace")
        pages = 0
    else:
        # Fallback: try LibreOffice to txt.
        try:
            text = _libreoffice(path, "txt")
        except HTTPException:
            raise HTTPException(status_code=415, detail=f"unsupported file type: {ext}")
        pages = 0

    text = text.strip()
    if not text:
        raise HTTPException(status_code=422, detail="document produced no text")

    return {
        "status": "ok",
        "text": text,
        "page_count": pages,
        "character_count": len(text),
        "word_count": _word_count(text),
        "extension": ext.lstrip("."),
        "mime_type": _guess_mime(ext),
    }


def _guess_mime(ext: str) -> str:
    return {
        ".pdf": "application/pdf",
        ".docx": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        ".pptx": "application/vnd.openxmlformats-officedocument.presentationml.presentation",
        ".xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        ".odt": "application/vnd.oasis.opendocument.text",
        ".ods": "application/vnd.oasis.opendocument.spreadsheet",
        ".odp": "application/vnd.oasis.opendocument.presentation",
        ".rtf": "application/rtf",
        ".txt": "text/plain",
        ".md": "text/markdown",
        ".csv": "text/csv",
        ".html": "text/html",
    }.get(ext, "application/octet-stream")
