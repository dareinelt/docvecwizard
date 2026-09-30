#!/usr/bin/env bash
#
# Run the integration test suite inside the app container so the tests can
# reach MySQL, Milvus, the embedding service and the converter over the Docker
# network. Requires the stack to be running (`docker compose up -d`).
#
# Usage: tests/integration/run.sh
set -euo pipefail

cd "$(dirname "$0")/../.."

echo "==> Ensuring the stack is up (health checks may take a moment)"
docker compose up -d --wait db milvus embedding converter app >/dev/null

echo "==> Running integration tests inside the app container"
docker compose run --rm \
  -v "$PWD/tests:/app/tests:ro" \
  app php /app/tests/integration/run.php
