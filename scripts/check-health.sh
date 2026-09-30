#!/usr/bin/env bash
# Zeigt den Gesamt-Health-Status des Stacks an.
set -euo pipefail
cd "$(dirname "$0")/.."
HTTPS_PORT="$(grep -E '^HTTPS_PORT=' .env 2>/dev/null | cut -d= -f2- | tr -d '[:space:]')"
HTTPS_PORT="${HTTPS_PORT:-8443}"
exec curl -sk "https://localhost:${HTTPS_PORT}/api/health"
