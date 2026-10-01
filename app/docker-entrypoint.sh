#!/bin/sh
# Runs as root at container start: prepare writable directories for the
# non-root `app` user, then drop privileges and exec the real command.
set -e

for d in \
  /srv/data/input \
  /srv/data/staging \
  /srv/data/exports; do
  mkdir -p "$d" 2>/dev/null || true
  # Shared volumes are also used by other containers (converter, web) running
  # under different UIDs, therefore they stay group/world writable.
  chmod 0777 "$d" 2>/dev/null || true
done

# SECURITY FIX: the TLS store holds the served private key. It was 0777 (any
# container user could swap the certificate/key). Only `app` (writer) and the
# nginx master process (root, reader) need access.
mkdir -p /srv/ssl
chown app:app /srv/ssl 2>/dev/null || true
chmod 0700 /srv/ssl 2>/dev/null || true

# SECURITY FIX: application-private state (logs, PHP sessions) is no longer
# world-writable. Sessions contain authentication state -> 0700.
mkdir -p /app/storage/logs /app/storage/sessions
chown -R app:app /app/storage 2>/dev/null || true
chmod 0750 /app/storage 2>/dev/null || true
chmod 0700 /app/storage/sessions 2>/dev/null || true

# FIX: PHP's defaults (upload_max_filesize=2M, post_max_size=8M) silently
# rejected larger uploads and archive imports; UPLOAD_MAX_SIZE was unused.
# Keep post_max_size in line with nginx's client_max_body_size.
UPLOAD_MAX_SIZE="${UPLOAD_MAX_SIZE:-100M}"
POST_MAX_SIZE="${POST_MAX_SIZE:-200M}"
# memory_limit: PHP's default of 128M is too small for storing a 100M document
# as Base64 blob (~1.34x the file size). Container mem_limit is 512m.
PHP_MEMORY_LIMIT="${PHP_MEMORY_LIMIT:-384M}"
case "$UPLOAD_MAX_SIZE$POST_MAX_SIZE$PHP_MEMORY_LIMIT" in
  *[!0-9KMGkmg-]*) echo "invalid UPLOAD_MAX_SIZE/POST_MAX_SIZE/PHP_MEMORY_LIMIT" >&2; exit 1 ;;
esac
cat > /usr/local/etc/php/conf.d/zz-docvecwizard.ini <<EOF
upload_max_filesize = ${UPLOAD_MAX_SIZE}
post_max_size = ${POST_MAX_SIZE}
memory_limit = ${PHP_MEMORY_LIMIT}
max_file_uploads = 100
expose_php = Off
display_errors = Off
log_errors = On
session.use_strict_mode = 1
session.use_only_cookies = 1
session.cookie_httponly = 1
session.cookie_samesite = Strict
EOF

# php-fpm's master process must stay root: it needs to reopen its stderr error
# log (docker pipes can't be reopened by a non-root process) and bind the socket.
# The pool workers drop to the `app` user via php-fpm.d/www.conf.
# CLI services (worker, migrate) run as the non-root `app` user.
if [ "$1" = "php-fpm" ]; then
  exec "$@"
fi

exec su-exec app "$@"
