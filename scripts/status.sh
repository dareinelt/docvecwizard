#!/usr/bin/env bash
# Kurzstatus: Compose-Dienste + Health.
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose ps
echo
scripts/check-health.sh
