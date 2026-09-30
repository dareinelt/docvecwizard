#!/bin/sh
# Runs as root at container start: prepare writable directories for the
# non-root `app` user, then drop privileges and exec the real command.
set -e

for d in \
  /srv/data/input \
  /srv/data/staging \
  /srv/data/exports \
  /srv/ssl \
  /app/storage; do
  mkdir -p "$d" 2>/dev/null || true
  chmod 0777 "$d" 2>/dev/null || true
done

# php-fpm's master process must stay root: it needs to reopen its stderr error
# log (docker pipes can't be reopened by a non-root process) and bind the socket.
# The pool workers drop to the `app` user via php-fpm.d/www.conf.
# CLI services (worker, migrate) run as the non-root `app` user.
if [ "$1" = "php-fpm" ]; then
  exec "$@"
fi

exec su-exec app "$@"
