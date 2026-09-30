#!/bin/sh
# Generate a fallback self-signed certificate when none is present, keep the
# shared SSL directory world-writable so the PHP `app` (uid 10003) can publish
# activated certificates there, and reload nginx when the served cert changes.
set -e

SSL_DIR=/etc/nginx/ssl
mkdir -p "$SSL_DIR"
chmod 0777 "$SSL_DIR"

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
    openssl req -x509 -newkey rsa:2048 -sha256 -nodes \
        -keyout "$SSL_DIR/key.pem" \
        -out "$SSL_DIR/cert.pem" \
        -days 3650 \
        -subj "/CN=${CN}" \
        -addext "subjectAltName=${SAN}" >/dev/null 2>&1
    chmod 0644 "$SSL_DIR/cert.pem" "$SSL_DIR/key.pem"
fi

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
