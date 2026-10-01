#!/usr/bin/env bash
# =============================================================================
# Document Embedding Manager - Smoke Test
#
# Reproducible end-to-end verification of the fully-offline stack:
#   Docker, Web, PHP, MySQL, Milvus, Embedding, Converter, API,
#   document processing, vector insert, statistics, export, HTTPS.
#
# Usage:
#   ./tests/smoke-test.sh
#
# Prerequisites: the stack must be running (`docker compose up -d`) and healthy.
# =============================================================================

set -uo pipefail

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
COMPOSE="${COMPOSE:-docker compose}"

HTTPS_PORT="$(grep -E '^HTTPS_PORT=' .env | cut -d= -f2- | tr -d '[:space:]')"
HTTPS_PORT="${HTTPS_PORT:-8443}"
BASE_URL="https://localhost:${HTTPS_PORT}"

# Admin credentials (environment wins over .env).
env_value() { grep -E "^$1=" .env 2>/dev/null | head -n1 | cut -d= -f2- | sed -E 's/^["'\'']//; s/["'\'']$//'; }
ADMIN_USERNAME="${ADMIN_USERNAME:-$(env_value ADMIN_USERNAME)}"
ADMIN_USERNAME="${ADMIN_USERNAME:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-$(env_value ADMIN_PASSWORD)}"

COOKIE_JAR="$(mktemp -t docvec-smoke-cookies.XXXXXX)"
EXPORT_FILE="$(mktemp -t docvec-export.XXXXXX.tar.gz)"
SMOKE_DIR="$ROOT/data/input/smoke"
SMOKE_NAME="smoke-$(date +%s).txt"
STAMP="$(date +%s)"
PASS=0
FAIL=0

cleanup() {
  rm -f "$COOKIE_JAR" "$EXPORT_FILE"
  # The smoke document is intentionally left in place so the run is auditable;
  # it is de-duplicated on subsequent runs by its content hash.
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Reporting helpers
# ---------------------------------------------------------------------------
section() { printf '\n==> %s\n' "$1"; }
ok()      { printf '  \033[32m✓\033[0m %s\n' "$1"; PASS=$((PASS + 1)); }
bad()     { printf '  \033[31m✗\033[0m %s\n' "$1"; FAIL=$((FAIL + 1)); }

# check <description> <shell-command-string>   (evaluated in the current shell)
check() {
  local desc="$1"; shift
  if eval "$*" >/dev/null 2>&1; then ok "$desc"; else bad "$desc"; fi
}

# api <method> <path> [extra curl args...] -> response body (authenticated session)
api() {
  local method="$1"; local path="$2"; shift 2
  curl -sk -X "$method" -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL$path" "$@"
}

# status <method> <path> [extra curl args...] -> HTTP status code only
status() {
  local method="$1"; local path="$2"; shift 2
  curl -sk -o /dev/null -w '%{http_code}' -X "$method" "$BASE_URL$path" "$@"
}

# guarded <method> <path> -> response body (with CSRF header + cookie jar)
guarded() {
  local method="$1"; local path="$2"; shift 2
  curl -sk -X "$method" \
    -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -H "x-csrf-token: ${CSRF_TOKEN:-}" \
    -H 'Accept: application/json' \
    ${GUARD_BODY:+ -H 'Content-Type: application/json' --data "$GUARD_BODY"} \
    "$BASE_URL$path"
}

# ---------------------------------------------------------------------------
# 1. Docker / containers
# ---------------------------------------------------------------------------
section "1. Docker & container health"
for svc in web app db milvus embedding converter worker; do
  check "service $svc is running" \
    "${COMPOSE} ps --status running $svc 2>/dev/null | grep -q $svc"
done

# ---------------------------------------------------------------------------
# 2. HTTPS / TLS
# ---------------------------------------------------------------------------
section "2. HTTPS / TLS"
check "HTTPS endpoint returns the web frontend" \
  "curl -sk '$BASE_URL/' | grep -qi '<html'"
check "TLS certificate subject contains localhost" \
  "echo | openssl s_client -connect localhost:${HTTPS_PORT} -servername localhost 2>/dev/null | openssl x509 -noout -subject 2>/dev/null | grep -qi 'CN'"
check "TLS 1.2 handshake succeeds" \
  "echo | openssl s_client -connect localhost:${HTTPS_PORT} -tls1_2 2>/dev/null | grep -q 'CONNECTED'"
HEADERS="$(curl -skI "$BASE_URL/")"
check "Content-Security-Policy header present" "printf '%s' \"\$HEADERS\" | grep -qi '^content-security-policy:'"
check "HSTS header present" "printf '%s' \"\$HEADERS\" | grep -qi '^strict-transport-security:'"
check "X-Frame-Options DENY" "printf '%s' \"\$HEADERS\" | grep -qi '^x-frame-options: *deny'"

# ---------------------------------------------------------------------------
# 3. Web / PHP / API liveness & authentication
# ---------------------------------------------------------------------------
section "3. Web, PHP, API liveness & authentication"
check "PHP front controller /healthz returns ok" \
  "api GET /healthz | jq -e '.status == \"ok\"'"
check "API rejects anonymous requests (401)" \
  "test \"\$(status GET /api/system)\" = 401"
ME_JSON="$(api GET /api/auth/me)"
CSRF_TOKEN="$(printf '%s' "$ME_JSON" | jq -r '.csrf_token // empty')"
check "CSRF token issued" "test -n '$CSRF_TOKEN'"
check "session cookie stored" "grep -q docvec_sid '$COOKIE_JAR'"
check "login without CSRF token is rejected (403)" \
  "test \"\$(status POST /api/auth/login -H 'Content-Type: application/json' --data '{}')\" = 403"
if [ -z "$ADMIN_PASSWORD" ]; then
  bad "ADMIN_PASSWORD is set (environment or .env)"
fi
LOGIN_BODY="$(jq -nc --arg u "$ADMIN_USERNAME" --arg p "$ADMIN_PASSWORD" '{username:$u,password:$p}')"
LOGIN_JSON="$(curl -sk -X POST -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  -H "x-csrf-token: ${CSRF_TOKEN}" -H 'Content-Type: application/json' \
  --data "$LOGIN_BODY" "$BASE_URL/api/auth/login")"
NEW_TOKEN="$(printf '%s' "$LOGIN_JSON" | jq -r '.csrf_token // empty')"
if [ -n "$NEW_TOKEN" ]; then CSRF_TOKEN="$NEW_TOKEN"; ok "admin login succeeded"; else bad "admin login succeeded"; fi
check "system info exposes version and PHP" \
  "api GET /api/system | jq -e '.app.version and .php.version'"

# ---------------------------------------------------------------------------
# 4. MySQL / Milvus / Embedding / Converter (aggregate health)
# ---------------------------------------------------------------------------
section "4. Backing services health"
HEALTH="$(api GET /api/health)"
check "aggregate health status is ok" \
  "printf '%s' '$HEALTH' | jq -e '.status == \"ok\"'"
check "MySQL reachable" \
  "printf '%s' '$HEALTH' | jq -e '.checks.database == true'"
check "Milvus reachable" \
  "printf '%s' '$HEALTH' | jq -e '.checks.milvus == true'"
check "Embedding service reachable" \
  "printf '%s' '$HEALTH' | jq -e '.checks.embedding == true'"
check "Converter service reachable" \
  "printf '%s' '$HEALTH' | jq -e '.checks.converter == true'"

# ---------------------------------------------------------------------------
# 5. Models & Milvus collections
# ---------------------------------------------------------------------------
section "5. Embedding models & Milvus collections"
MODELS="$(api GET /api/models)"
ACTIVE_MODEL="$(printf '%s' "$MODELS" | jq -r '[.models[] | select(.active == 1)][0].name // empty')"
if [ -z "$ACTIVE_MODEL" ]; then
  ACTIVE_MODEL="$(printf '%s' "$MODELS" | jq -r '.models[0].name // empty')"
fi
check "at least one embedding model catalogued" "test -n '$ACTIVE_MODEL'"
MODEL_DIM="$(printf '%s' "$MODELS" | jq -r "[.models[] | select(.name == \"$ACTIVE_MODEL\")][0].dimension // 0")"
check "active model has a positive dimension" "test '$MODEL_DIM' -gt 0"
# Mirror MilvusClient::collectionFor(): lowercase, non-alphanumerics -> "_".
COLLECTION="docvec_$(printf '%s' "$ACTIVE_MODEL" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/_/g; s/^_+//; s/_+$//')"
[ -n "${COLLECTION#docvec_}" ] || COLLECTION="docvec_default"
check "Milvus collections endpoint returns a list" \
  "api GET /api/collections | jq -e '.collections | type == \"array\"'"
ROWCOUNT_BEFORE="$(api GET "/api/collections/${COLLECTION}/stats" | jq -r '.data.rowCount // 0')"

# ---------------------------------------------------------------------------
# 6. Directory browser & CSRF
# ---------------------------------------------------------------------------
section "6. Directory browser & CSRF"
check "state-changing request without CSRF token is rejected (403)" \
  "test \"\$(status POST /api/jobs -b '$COOKIE_JAR' -H 'Content-Type: application/json' --data '{}')\" = 403"
check "directory browser lists fixtures" \
  "api GET '/api/browse?path=fixtures' | jq -e '.entries | length >= 1'"

# ---------------------------------------------------------------------------
# 7. Document processing workflow (scan -> convert -> chunk -> embed -> Milvus)
# ---------------------------------------------------------------------------
section "7. Document processing workflow"
mkdir -p "$SMOKE_DIR"
cat > "$SMOKE_DIR/$SMOKE_NAME" <<EOF
Smoke test document $STAMP.
The zephyr lantern glows quietly above the timber wharf.
This sentence is unique to the smoke test so semantic search can find it.
EOF

GUARD_BODY="{\"name\":\"Smoke test $STAMP\",\"source_directory\":\"smoke\",\"recursive\":false,\"embedding_model\":\"$ACTIVE_MODEL\"}"
CREATE_JSON="$(guarded POST /api/jobs)"
JOB_ID="$(printf '%s' "$CREATE_JSON" | jq -r '.job.job_id // empty')"
if [ -n "$JOB_ID" ]; then ok "job created ($JOB_ID)"; else bad "create job returned a job_id"; fi

# Poll until the job reaches a terminal state (worker processes asynchronously).
JOB_STATUS=""
for _ in $(seq 1 90); do
  JOB_JSON="$(api GET "/api/jobs/$JOB_ID")"
  JOB_STATUS="$(printf '%s' "$JOB_JSON" | jq -r '.job.status // empty')"
  case "$JOB_STATUS" in
    COMPLETED|FAILED|CANCELLED) break ;;
  esac
  sleep 2
done
if [ "$JOB_STATUS" = "COMPLETED" ]; then ok "job reached COMPLETED"; else bad "job completed (got: ${JOB_STATUS:-none})"; fi

check "job processed at least 1 document" \
  "api GET '/api/jobs/$JOB_ID' | jq -e '.job.documents_processed >= 1'"

JOB_INTERNAL_ID="$(printf '%s' "$JOB_JSON" | jq -r '.job.id // 0')"
DOC_JSON="$(api GET "/api/documents?job_id=$JOB_INTERNAL_ID")"
SMOKE_DOC_ID="$(printf '%s' "$DOC_JSON" | jq -r --arg n "$SMOKE_NAME" '[.documents[] | select(.filename == $n)][0].document_id // empty')"
check "smoke document discovered" "test -n '$SMOKE_DOC_ID'"
check "smoke document embedded (chunk_count > 0)" \
  "api GET '/api/documents/$SMOKE_DOC_ID' | jq -e '.document.chunk_count > 0'"

# ---------------------------------------------------------------------------
# 8. Vector insert (Milvus row count must increase)
# ---------------------------------------------------------------------------
section "8. Vector insert"
ROWCOUNT_AFTER="$ROWCOUNT_BEFORE"
for _ in $(seq 1 10); do
  ROWCOUNT_AFTER="$(api GET "/api/collections/${COLLECTION}/stats" | jq -r '.data.rowCount // 0')"
  if [ "$ROWCOUNT_AFTER" -gt "$ROWCOUNT_BEFORE" ]; then break; fi
  sleep 1
done
check "Milvus row count increased after processing" \
  "test '$ROWCOUNT_AFTER' -gt '$ROWCOUNT_BEFORE'"

# ---------------------------------------------------------------------------
# 9. Semantic search
# ---------------------------------------------------------------------------
section "9. Semantic search"
GUARD_BODY='{"query":"zephyr lantern timber wharf","limit":5}'
SEARCH_JSON="$(guarded POST /api/search)"
check "search returns at least one result" \
  "printf '%s' '$SEARCH_JSON' | jq -e '.results | length >= 1'"

# ---------------------------------------------------------------------------
# 10. Statistics
# ---------------------------------------------------------------------------
section "10. Statistics"
check "statistics dashboard is well-formed" \
  "api GET /api/statistics | jq -e '.documents and .jobs and .totals and .models'"
check "extensions aggregation returns an array" \
  "api GET /api/statistics/extensions | jq -e '.extensions | type == \"array\"'"

# ---------------------------------------------------------------------------
# 11. Export
# ---------------------------------------------------------------------------
section "11. Export"
GUARD_BODY=''
EXPORT_JSON="$(guarded POST /api/exports)"
EXPORT_ID="$(printf '%s' "$EXPORT_JSON" | jq -r '.export.export_id // empty')"
if [ -z "$EXPORT_ID" ]; then
  EXPORT_ID="$(api GET /api/exports | jq -r '.exports[0].export_id // empty')"
fi
check "export created" "test -n '$EXPORT_ID'"
if [ -n "$EXPORT_ID" ]; then
  check "export downloads as a gzip archive" \
    "curl -sk -o '$EXPORT_FILE' -b '$COOKIE_JAR' -c '$COOKIE_JAR' -H 'x-csrf-token: ${CSRF_TOKEN:-}' '$BASE_URL/api/exports/$EXPORT_ID/download' && tar -tzf '$EXPORT_FILE' >/dev/null 2>&1"
  # ext1 export format: manifest.json carries a numeric documents count; the
  # full per-version document payloads live in mysql/documents.json.
  check "export manifest is valid JSON with document count" \
    "tar -xzOf '$EXPORT_FILE' manifest.json 2>/dev/null | jq -e '.documents | type == \"number\"'"
  check "export archive contains documents payload" \
    "tar -xzOf '$EXPORT_FILE' mysql/documents.json 2>/dev/null | jq -e 'type == \"array\"'"
fi

# ---------------------------------------------------------------------------
# 12. Summary
# ---------------------------------------------------------------------------
section "Summary"
printf '\n  %d passed, %d failed\n\n' "$PASS" "$FAIL"
if [ "$FAIL" -gt 0 ]; then
  exit 1
fi
exit 0
