#!/bin/sh
# Generate a fallback self-signed certificate when none is present, hand the
# SSL directory to the PHP `app` user (uid 10003) so it can publish activated
# certificates there, and reload nginx when the served cert changes.
set -e

SSL_DIR=/etc/nginx/ssl
APP_UID="${APP_UID:-10003}"
mkdir -p "$SSL_DIR"
# SECURITY FIX: was chmod 0777 (+ key.pem 0644) - the private key was readable
# and replaceable by every user/container sharing the volume. The nginx
# master process runs as root and can read it regardless of ownership.
chown "$APP_UID:$APP_UID" "$SSL_DIR" 2>/dev/null || true
chmod 0700 "$SSL_DIR"

entry_for() {
    case "$1" in
        *:*)          echo "IP:$1" ;;   # IPv6
        *[!0-9.]*)    echo "DNS:$1" ;;  # hostname
        *)            echo "IP:$1" ;;   # IPv4
    esac
}

build_san() {
    out=""
    for h in $(echo "${APP_HOSTNAMES:-localhost}" | tr ',' ' '); do
        [ -z "$h" ] && continue
        e="$(entry_for "$h")"
        out="${out:+$out,}$e"
    done
    echo "$out"
}

CN="$(echo "${APP_HOSTNAMES:-localhost}" | tr ',' ' ' | awk '{print $1}')"
[ -z "$CN" ] && CN="localhost"

if [ ! -f "$SSL_DIR/cert.pem" ] || [ ! -f "$SSL_DIR/key.pem" ]; then
    SAN="$(build_san)"
    umask 077
    openssl req -x509 -newkey rsa:2048 -sha256 -nodes \
        -keyout "$SSL_DIR/key.pem" \
        -out "$SSL_DIR/cert.pem" \
        -days 3650 \
        -subj "/CN=${CN}" \
        -addext "subjectAltName=${SAN}" >/dev/null 2>&1
    umask 022
    chmod 0600 "$SSL_DIR/key.pem"
    chmod 0644 "$SSL_DIR/cert.pem"
    chown "$APP_UID:$APP_UID" "$SSL_DIR/cert.pem" "$SSL_DIR/key.pem" 2>/dev/null || true
fi
# Tighten keys left over from older versions (were 0644).
[ -f "$SSL_DIR/key.pem" ] && chmod 0600 "$SSL_DIR/key.pem"

# Reload nginx when the served certificate is replaced (TLS activation).
(
    last="$(stat -c %Y "$SSL_DIR/cert.pem" 2>/dev/null || echo 0)"
    while true; do
        sleep 10
        cur="$(stat -c %Y "$SSL_DIR/cert.pem" 2>/dev/null || echo 0)"
        if [ "$cur" != "$last" ]; then
            last="$cur"
            nginx -s reload >/dev/null 2>&1 || true
        fi
    done
) &

exec nginx -g 'daemon off;'
