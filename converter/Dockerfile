# Document converter: PDF/DOCX/PPTX/XLSX/... -> normalized text/markdown.
# Uses poppler-utils, pandoc and LibreOffice headless. Non-root user.
FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PATH="/opt/venv/bin:$PATH"

RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 python3-venv \
        pandoc \
        poppler-utils \
        libreoffice-writer libreoffice-calc libreoffice-impress \
        fonts-liberation \
        tini \
    && rm -rf /var/lib/apt/lists/*

RUN useradd --create-home --uid 10002 appuser

WORKDIR /app

COPY requirements.txt .

# Debian's system Python is PEP 668 externally-managed, so install deps into a
# dedicated virtualenv rather than polluting the OS interpreter.
RUN python3 -m venv /opt/venv \
    && pip install --no-cache-dir -r requirements.txt

COPY src ./src

RUN chown -R appuser:appuser /app
USER appuser

EXPOSE 8001

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["python", "-m", "uvicorn", "src.main:app", "--host", "0.0.0.0", "--port", "8001"]
