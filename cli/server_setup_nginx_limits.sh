#!/bin/bash
# cli/server_setup_nginx_limits.sh — one step of cli/server_setup.sh (v2).
#
# Stock nginx caps a tracker under load: Ubuntu 24.04 ships
# worker_connections 768, Debian 12 ships 1024, and the 2026-11 load test
# (docs/loadtest-2026-11) hit "768 worker_connections are not enough" with
# HTTP 500s at 320-480 rps. This step raises the STOCK value to 4096 and adds
# a top-level worker_rlimit_nofile 16384 when the directive is absent. Values
# someone set by hand (2048, 8192, ...) are left exactly as they are.
#
# Edits go backup -> validate (nginx -t) -> reload, with a rollback from the
# backup when validation fails. Idempotent: a second run changes nothing and
# reloads nothing.
#
# Standalone-testable: NGINX_CONF, NGINX_BIN and NGINX_RELOAD can come from
# the environment (tests point them at fixtures and fakes); ORBITRA_SETUP_TMP
# reuses the caller's temp dir for backups instead of making a fresh one.
#
# Never fatal: an absent nginx or a rejected config leaves the install
# working and exits 0 — this step is a recommendation, not a requirement.

set -euo pipefail

nginx_conf="${NGINX_CONF:-/etc/nginx/nginx.conf}"
nginx_bin="${NGINX_BIN:-}"
reload_cmd="${NGINX_RELOAD:-systemctl reload nginx}"

if [ -z "$nginx_bin" ]; then
    if command -v nginx >/dev/null 2>&1; then
        nginx_bin="$(command -v nginx)"
    elif [ -x /usr/sbin/nginx ]; then
        nginx_bin=/usr/sbin/nginx
    fi
fi

if [ ! -f "$nginx_conf" ]; then
    echo "  > NOTE: $nginx_conf not found — nginx limits step skipped"
    exit 0
fi
if [ -z "$nginx_bin" ]; then
    echo "  > NOTE: nginx binary not found — nginx limits step skipped (nothing validated, nothing changed)"
    exit 0
fi

if [ -n "${ORBITRA_SETUP_TMP:-}" ] && [ -d "$ORBITRA_SETUP_TMP" ]; then
    tmp="$ORBITRA_SETUP_TMP"
else
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
fi

# Portable rewrite — no `sed -i` (its backup-argument syntax differs between
# GNU and BSD sed): filter into a temp file, then pour the result back over
# the original inode.
rewrite() { # rewrite <sed-expression>
    sed -E "$1" "$nginx_conf" > "$tmp/nginx.conf.rewrite" && cat "$tmp/nginx.conf.rewrite" > "$nginx_conf"
}

changed=0

# --- worker_connections: only the two stock values are raised ----------------
wc_value="$(sed -n -E 's/^[[:space:]]*worker_connections[[:space:]]+([0-9]+);.*/\1/p' "$nginx_conf" | head -n 1)"
if [ "$wc_value" = "768" ] || [ "$wc_value" = "1024" ]; then
    cp "$nginx_conf" "$tmp/nginx.conf.before"
    rewrite 's/^([[:space:]]*)worker_connections[[:space:]]+[0-9]+;(.*)$/\1worker_connections 4096;\2/'
    changed=1
    echo "  > worker_connections $wc_value -> 4096"
fi

# --- worker_rlimit_nofile at the main level, only when absent ----------------
if ! grep -Eq '^[[:space:]]*worker_rlimit_nofile[[:space:]]' "$nginx_conf"; then
    if [ "$changed" -eq 0 ]; then
        cp "$nginx_conf" "$tmp/nginx.conf.before"
    fi
    if grep -Eq '^events[[:space:]]*\{' "$nginx_conf"; then
        awk -v ins='worker_rlimit_nofile 16384;' '
            !done && $0 ~ /^events[[:space:]]*\{/ { print ins; print ""; done = 1 }
            { print }
        ' "$nginx_conf" > "$tmp/nginx.conf.rlimit" && cat "$tmp/nginx.conf.rlimit" > "$nginx_conf"
    else
        awk -v ins='worker_rlimit_nofile 16384;' '
            NR == 1 { print ins; print "" }
            { print }
        ' "$nginx_conf" > "$tmp/nginx.conf.rlimit" && cat "$tmp/nginx.conf.rlimit" > "$nginx_conf"
    fi
    changed=1
    echo "  > worker_rlimit_nofile 16384 added (was absent)"
fi

if [ "$changed" -eq 0 ]; then
    echo "  > nginx limits already configured — nothing changed"
    exit 0
fi

if ! "$nginx_bin" -t >/dev/null 2>&1; then
    cat "$tmp/nginx.conf.before" > "$nginx_conf"
    echo "  ! WARNING: nginx -t rejected the limits change — $nginx_conf restored unchanged"
    exit 0
fi

# Word-splitting is the point: NGINX_RELOAD carries a whole command line.
$reload_cmd || echo "  ! NOTE: config valid (nginx -t) but the reload command failed — reload nginx by hand"
echo "  > nginx limits applied and validated (nginx -t)"
exit 0
