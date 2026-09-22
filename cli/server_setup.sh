#!/bin/bash
# Orbitra — root-side server setup. Idempotent: safe to run any number of times.
#
# Everything the tracker needs from root lives here, so that fresh installs
# (install.sh calls this) and servers installed by an older install.sh get the
# SAME result. The panel's "Update" button runs as the web user and can never
# do this part itself; the panel instead shows the command below until the
# server reports the current SERVER_SETUP_VERSION.
#
#   curl -fsSL https://raw.githubusercontent.com/fenjo26/Orbitra.link/vX.Y.Z/cli/server_setup.sh \
#     | sudo ORBITRA_REF=vX.Y.Z bash
#
# Fetching from a release tag (not running the copy inside /var/www/orbitra) is
# deliberate: that directory belongs to the web user, and a root command must
# not execute a file the web user can rewrite.
#
# What it does:
#   1. Installs the root helpers the panel may call through sudo, each of which
#      polices its own arguments: orbitra-catcert, orbitra-issue-cert,
#      orbitra-delete-cert, orbitra-install-nginx-conf.
#   2. Rewrites /etc/sudoers.d/orbitra-ssl to exactly those helpers plus
#      "nginx -t" and "systemctl reload nginx". This REMOVES the old rules
#      "NOPASSWD: /usr/bin/certbot" (root via --deploy-hook) and the two
#      "cp ... /etc/nginx/sites-available/orbitra" rules (root via an nginx
#      config) — security audit #4. The file is checked with visudo before it
#      replaces the old one.
#   3. Keeps /etc/letsencrypt's public half readable (plus a renewal hook).
#   4. Schedules the click-spool worker for the web user.
#   5. Records SERVER_SETUP_VERSION in /etc/orbitra/server-setup-version, which
#      the panel reads to stop asking.
#
# Environment: ORBITRA_DIR (default /var/www/orbitra), ORBITRA_WEB_USER
# (default www-data), ORBITRA_REF (release tag to fetch companion files from
# when the script runs without a checkout next to it).

set -euo pipefail

SERVER_SETUP_VERSION=1

main() {
    if [ "$(id -u)" -ne 0 ]; then
        echo "Run as root: sudo bash $0" >&2
        exit 1
    fi

    local orbitra_dir="${ORBITRA_DIR:-/var/www/orbitra}"
    local web_user="${ORBITRA_WEB_USER:-www-data}"
    local ref="${ORBITRA_REF:-}"
    local sudoers_file="/etc/sudoers.d/orbitra-ssl"
    local script_dir=""
    if [ -n "${BASH_SOURCE[0]:-}" ] && [ -f "${BASH_SOURCE[0]}" ]; then
        script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    fi

    echo "Orbitra server setup v${SERVER_SETUP_VERSION}"

    if ! id "$web_user" >/dev/null 2>&1; then
        echo "ERROR: web user '$web_user' does not exist (set ORBITRA_WEB_USER)." >&2
        exit 1
    fi
    if ! command -v php >/dev/null 2>&1; then
        echo "ERROR: php CLI not found — the nginx helper is a PHP script." >&2
        exit 1
    fi

    # Global on purpose: the EXIT trap runs after main() has returned.
    ORBITRA_SETUP_TMP="$(mktemp -d /tmp/orbitra_setup.XXXXXX)"
    trap 'rm -rf "${ORBITRA_SETUP_TMP:-}"' EXIT
    local tmp="$ORBITRA_SETUP_TMP"

    # --- 1. helpers -----------------------------------------------------------
    echo "  > Installing root helpers into /usr/local/bin"
    local nginx_helper_src="$tmp/orbitra-install-nginx-conf"
    if [ -n "$script_dir" ] && [ -f "$script_dir/orbitra-install-nginx-conf" ]; then
        cp "$script_dir/orbitra-install-nginx-conf" "$nginx_helper_src"
    elif [ -n "$ref" ]; then
        curl -fsSL "https://raw.githubusercontent.com/fenjo26/Orbitra.link/${ref}/cli/orbitra-install-nginx-conf" \
            -o "$nginx_helper_src"
    else
        echo "ERROR: cannot find cli/orbitra-install-nginx-conf next to this script; set ORBITRA_REF=<release tag>." >&2
        exit 1
    fi
    if ! head -c 64 "$nginx_helper_src" | grep -q '^#!/usr/bin/env php' \
        || ! php -l "$nginx_helper_src" >/dev/null 2>&1; then
        echo "ERROR: downloaded orbitra-install-nginx-conf is not a valid PHP script — aborting." >&2
        exit 1
    fi
    install -o root -g root -m 0755 "$nginx_helper_src" /usr/local/bin/orbitra-install-nginx-conf
    mkdir -p /var/lib/orbitra
    chmod 0755 /var/lib/orbitra

    cat > "$tmp/orbitra-catcert" <<'ORBITRA_CATCERT'
#!/bin/sh
# Print ONE public certificate file from /etc/letsencrypt, and nothing else.
# Reached through a NOPASSWD sudoers rule, so it must police its own argument:
# a sudoers rule without arguments allows any. Private keys are not listed
# below and `..` is refused outright — in `case`, a * matches slashes too, so
# without that check "live/../../root/x/fullchain.pem" would pass the pattern.
set -eu
[ $# -eq 1 ] || { echo "usage: orbitra-catcert <path>" >&2; exit 2; }
case "$1" in
    *..*) exit 2 ;;
esac
case "$1" in
    /etc/letsencrypt/live/*/fullchain.pem) ;;
    /etc/letsencrypt/live/*/chain.pem) ;;
    /etc/letsencrypt/live/*/cert.pem) ;;
    /etc/letsencrypt/archive/*/fullchain*.pem) ;;
    /etc/letsencrypt/archive/*/chain*.pem) ;;
    /etc/letsencrypt/archive/*/cert*.pem) ;;
    *) exit 2 ;;
esac
exec cat -- "$1"
ORBITRA_CATCERT

    cat > "$tmp/orbitra-issue-cert" <<'ORBITRA_ISSUECERT'
#!/bin/sh
# Issue ONE Let's Encrypt certificate for ONE domain, and nothing else.
# Reached through a NOPASSWD sudoers rule that carries no argument pattern
# (wildcards in arguments are what sudo-rs rejects), so it must police its
# own input: exactly one domain plus at most one renewal flag, the argument
# set otherwise fixed. Replaces the old blanket "NOPASSWD: /usr/bin/certbot"
# rule, which let the web user pass certbot any --deploy-hook it liked.
set -eu
[ $# -ge 1 ] && [ $# -le 2 ] || { echo "usage: orbitra-issue-cert <domain> [--force-renewal]" >&2; exit 2; }
domain=$(printf '%s' "$1" | tr 'A-Z' 'a-z')
[ ${#domain} -le 253 ] || { echo "domain too long" >&2; exit 2; }
# `case` sees the whole argument (grep would test it line by line).
case "$domain" in
    ""|-*|*[!a-z0-9.-]*) echo "invalid domain" >&2; exit 2 ;;
esac
printf '%s' "$domain" | grep -Eq '^[a-z0-9.-]+\.[a-z]{2,}$' || { echo "invalid domain" >&2; exit 2; }
case "${2-}" in
    "") flag="--keep-until-expiring" ;;
    --keep-until-expiring) flag="--keep-until-expiring" ;;
    --force-renewal) flag="--force-renewal" ;;
    *) echo "unknown argument: $2" >&2; exit 2 ;;
esac
ACME_WEBROOT=/var/www/orbitra/var/acme
mkdir -p "$ACME_WEBROOT/.well-known/acme-challenge"
# Under set -e a failed issuance aborts here, before the chmod — the
# permission repair below only ever follows a certificate that was written.
certbot certonly --webroot -w "$ACME_WEBROOT" -n -d "$domain" \
    --agree-tos --register-unsafely-without-email "$flag"
# Directory bits only: private keys stay 0600 root-only files inside.
chmod 0755 /etc/letsencrypt /etc/letsencrypt/live /etc/letsencrypt/archive 2>/dev/null || true
ORBITRA_ISSUECERT

    cat > "$tmp/orbitra-delete-cert" <<'ORBITRA_DELETECERT'
#!/bin/sh
# Delete ONE Let's Encrypt certificate line for ONE domain, and nothing else.
set -eu
[ $# -eq 1 ] || { echo "usage: orbitra-delete-cert <domain>" >&2; exit 2; }
domain=$(printf '%s' "$1" | tr 'A-Z' 'a-z')
[ ${#domain} -le 253 ] || { echo "domain too long" >&2; exit 2; }
case "$domain" in
    ""|-*|*[!a-z0-9.-]*) echo "invalid domain" >&2; exit 2 ;;
esac
printf '%s' "$domain" | grep -Eq '^[a-z0-9.-]+\.[a-z]{2,}$' || { echo "invalid domain" >&2; exit 2; }
exec certbot delete --cert-name "$domain" -n
ORBITRA_DELETECERT

    local helper
    for helper in orbitra-catcert orbitra-issue-cert orbitra-delete-cert; do
        install -o root -g root -m 0755 "$tmp/$helper" "/usr/local/bin/$helper"
    done

    # --- 2. sudoers -------------------------------------------------------------
    echo "  > Rewriting $sudoers_file (old certbot / cp rules removed)"
    local nginx_bin systemctl_bin
    nginx_bin="$(command -v nginx || echo /usr/sbin/nginx)"
    systemctl_bin="$(command -v systemctl || echo /bin/systemctl)"
    # The panel calls "sudo nginx -t" / "sudo systemctl reload nginx" by bare
    # name; sudo resolves them through secure_path, so list the real paths
    # plus the historical ones (a usrmerge box has both).
    {
        echo "# Managed by Orbitra cli/server_setup.sh v${SERVER_SETUP_VERSION} — rewritten on every run."
        echo "$web_user ALL=(ALL) NOPASSWD: $nginx_bin -t"
        [ "$nginx_bin" != "/usr/sbin/nginx" ] && echo "$web_user ALL=(ALL) NOPASSWD: /usr/sbin/nginx -t"
        echo "$web_user ALL=(ALL) NOPASSWD: $systemctl_bin reload nginx"
        [ "$systemctl_bin" != "/bin/systemctl" ] && echo "$web_user ALL=(ALL) NOPASSWD: /bin/systemctl reload nginx"
        echo "$web_user ALL=(ALL) NOPASSWD: /usr/local/bin/orbitra-install-nginx-conf"
        echo "$web_user ALL=(ALL) NOPASSWD: /usr/local/bin/orbitra-catcert"
        echo "$web_user ALL=(ALL) NOPASSWD: /usr/local/bin/orbitra-issue-cert"
        echo "$web_user ALL=(ALL) NOPASSWD: /usr/local/bin/orbitra-delete-cert"
    } > "$tmp/sudoers"
    chmod 0440 "$tmp/sudoers"
    if command -v visudo >/dev/null 2>&1 && ! visudo -c -f "$tmp/sudoers" >/dev/null 2>&1; then
        echo "ERROR: the new sudoers file did not pass visudo -c; $sudoers_file left unchanged:" >&2
        visudo -c -f "$tmp/sudoers" 2>&1 | sed 's/^/    /' >&2
        exit 1
    fi
    mkdir -p /etc/sudoers.d
    install -o root -g root -m 0440 "$tmp/sudoers" "$sudoers_file.new"
    mv -f "$sudoers_file.new" "$sudoers_file"

    # --- 3. /etc/letsencrypt readability --------------------------------------
    # certbot creates live/ and archive/ as 0700 root, so the web user could not
    # even check that a certificate exists. Directory bits only — private keys
    # stay 0600 root-only files. certbot re-applies 0700 on some renewals, so a
    # deploy hook repeats it.
    echo "  > Keeping the public half of /etc/letsencrypt readable"
    chmod 0755 /etc/letsencrypt /etc/letsencrypt/live /etc/letsencrypt/archive 2>/dev/null || true
    mkdir -p /etc/letsencrypt/renewal-hooks/deploy
    cat > /etc/letsencrypt/renewal-hooks/deploy/00-orbitra-readable.sh <<'ORBITRA_HOOK'
#!/bin/sh
# Orbitra: keep the public half of /etc/letsencrypt readable by the web user.
# Directory bits only — private key files keep their own 0600 root-only mode.
chmod 0755 /etc/letsencrypt /etc/letsencrypt/live /etc/letsencrypt/archive 2>/dev/null || true
ORBITRA_HOOK
    chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/00-orbitra-readable.sh

    # --- 4. click-spool worker ----------------------------------------------------
    local marker="# orbitra-click-spool"
    if command -v crontab >/dev/null 2>&1 && [ -f "$orbitra_dir/cli/click_spool_cron.php" ]; then
        if ! crontab -u "$web_user" -l 2>/dev/null | grep -qF "$marker"; then
            echo "  > Scheduling the click-spool worker for $web_user"
            mkdir -p "$orbitra_dir/var/logs"
            chown "$web_user:$web_user" "$orbitra_dir/var/logs" 2>/dev/null || true
            {
                crontab -u "$web_user" -l 2>/dev/null || true
                echo "* * * * * php $orbitra_dir/cli/click_spool_cron.php --quiet >> $orbitra_dir/var/logs/click_spool.log 2>&1 $marker"
            } | crontab -u "$web_user" -
        fi
    fi

    # --- 5. marker ----------------------------------------------------------------
    mkdir -p /etc/orbitra
    chmod 0755 /etc/orbitra
    echo "$SERVER_SETUP_VERSION" > /etc/orbitra/server-setup-version
    chmod 0644 /etc/orbitra/server-setup-version

    # --- self-check ---------------------------------------------------------------
    local ok=1 rc=0
    if command -v sudo >/dev/null 2>&1; then
        sudo -u "$web_user" sudo -n /usr/local/bin/orbitra-issue-cert 'not-a-valid-domain' >/dev/null 2>&1 || rc=$?
        if [ "$rc" -ne 2 ]; then
            ok=0
            echo "  ! WARNING: $web_user cannot run orbitra-issue-cert through sudo (exit $rc)."
        fi
        rc=0
        printf 'server {\n    listen 80;\n    root %s;\n}\n' "/var/www/orbitra" \
            | sudo -u "$web_user" sudo -n /usr/local/bin/orbitra-install-nginx-conf --check >/dev/null 2>&1 || rc=$?
        if [ "$rc" -ne 0 ]; then
            ok=0
            echo "  ! WARNING: $web_user cannot run orbitra-install-nginx-conf through sudo (exit $rc)."
        fi
    fi

    if [ "$ok" -eq 1 ]; then
        echo "Done. Server setup is at version ${SERVER_SETUP_VERSION}; reload the panel's Update page."
    else
        echo "Finished with warnings (see above). Server setup marker written: v${SERVER_SETUP_VERSION}."
    fi
}

main "$@"
