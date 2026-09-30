#!/usr/bin/env bash
# MariaDB-Dump in eine Datei schreiben. Usage: scripts/backup-db.sh [datei]
set -euo pipefail
cd "$(dirname "$0")/.."
OUT="${1:-backup-$(date +%Y%m%d-%H%M%S).sql}"
docker compose exec -T db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' > "$OUT"
echo "Backup geschrieben: $OUT"
