#!/usr/bin/env bash
# Milvus-Volume sichern. Usage: scripts/backup-milvus.sh [datei.tar.gz]
set -euo pipefail
cd "$(dirname "$0")/.."
OUT="${1:-milvus-backup-$(date +%Y%m%d-%H%M%S).tar.gz}"
docker run --rm -v docvecwizard_milvus_data:/data -v "$PWD":/backup alpine \
  tar czf "/backup/$OUT" -C /data .
echo "Backup geschrieben: $OUT"
