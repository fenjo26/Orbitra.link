#!/usr/bin/env bash
# Orbitra — release matrix: does install.sh still fit every OS we advertise?
#
#     sudo bash cli/os_matrix.sh                 # every supported release
#     sudo bash cli/os_matrix.sh resolute noble  # just these
#
# Run it before tagging a release. It answers one question per supported
# release — "would install.sh still work here?" — without provisioning a
# single VPS, and it is deliberately fast: the checks that need systemd, a
# real nginx or a public DNS name are NOT here, they are the smoke tests at
# the end of install.sh itself, which run on the real server.
#
# What this catches is the other half, and the half that has actually broken
# releases: the operating system changing underneath a script that did not.
# Both v1.4.1 field failures were of exactly that kind — Ubuntu 25.10 replaced
# sudo with sudo-rs, which rejects the wildcards our sudoers rules used, and
# newer cloud images boot into an unattended-upgrade that holds the dpkg lock.
# Neither was a regression in Orbitra; both would have shown up here in
# seconds.
#
# Backends, chosen automatically:
#   docker      — one container per release. Needs a working Docker daemon.
#   debootstrap — one chroot per release, built from the distribution's own
#                 archive and cached under /var/cache/orbitra-os-matrix.
#                 Linux and root only.
# Force one with ORBITRA_MATRIX_BACKEND=docker|debootstrap.

set -u

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALLER="$REPO_ROOT/install.sh"
CACHE_DIR="${ORBITRA_MATRIX_CACHE:-/var/cache/orbitra-os-matrix}"

# Every release the README promises, newest first. focal and bullseye are
# deliberately absent: they ship only PHP 7.4 while the panel needs 8.0+, so
# they were dropped from the advertised set rather than promised and broken.
# They can still be checked by name — the matrix reports their PHP failure
# honestly if anyone runs them.
DEFAULT_SUITES="resolute questing noble jammy trixie bookworm"

suite_vendor() {
    case "$1" in
        focal|jammy|noble|questing|resolute) echo ubuntu ;;
        bullseye|bookworm|trixie)            echo debian ;;
        *)                                   echo unknown ;;
    esac
}

suite_pretty() {
    case "$1" in
        focal)    echo "Ubuntu 20.04" ;;
        jammy)    echo "Ubuntu 22.04" ;;
        noble)    echo "Ubuntu 24.04" ;;
        questing) echo "Ubuntu 25.10" ;;
        resolute) echo "Ubuntu 26.04" ;;
        bullseye) echo "Debian 11" ;;
        bookworm) echo "Debian 12" ;;
        trixie)   echo "Debian 13" ;;
        *)        echo "$1" ;;
    esac
}

suite_mirror() {
    if [ "$(suite_vendor "$1")" = "debian" ]; then
        echo "http://deb.debian.org/debian"
    else
        echo "http://archive.ubuntu.com/ubuntu"
    fi
}

suite_image() {
    if [ "$(suite_vendor "$1")" = "debian" ]; then
        echo "debian:$1-slim"
    else
        echo "ubuntu:$1"
    fi
}

# ---------------------------------------------------------------- guest checks
#
# Everything below GUEST_SCRIPT runs INSIDE the release under test, with the
# installer mounted at /tmp/orbitra/install.sh. It prints one line per check:
#
#     CHECK|<name>|PASS|FAIL|SKIP|<detail>
#
# and nothing else on stdout, so the host side can stay a dumb parser.
read -r -d '' GUEST_SCRIPT <<'GUEST_EOF'
set -u
INSTALLER=/tmp/orbitra/install.sh
emit() { printf 'CHECK|%s|%s|%s\n' "$1" "$2" "$3"; }

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq >/tmp/apt-update.log 2>&1 \
    || { emit apt_update FAIL "$(tail -n1 /tmp/apt-update.log)"; exit 0; }

# 1. Does this release's bash still accept the installer at all? Cheap, and it
#    is the check that catches syntax a newer or older bash reads differently.
if bash -n "$INSTALLER" 2>/tmp/syn.log; then
    emit bash_syntax PASS "bash $(bash --version | head -n1 | sed 's/.*version //; s/ .*//')"
else
    emit bash_syntax FAIL "$(head -n1 /tmp/syn.log)"
fi

# 2. The sudoers file the installer writes, checked by every sudo implementation
#    this release ships. Both are tested when both exist: which one a given
#    machine ends up with depends on the image, and a rule rejected by either is
#    a rule some user will silently lose. This is the check that would have
#    caught sudo-rs.
sed -n 's/^echo "\(www-data ALL=[^"]*\)" *>>\{0,1\} *\$SUDOERS_FILE$/\1/p' "$INSTALLER" > /tmp/orbitra-sudoers
if [ ! -s /tmp/orbitra-sudoers ]; then
    emit sudoers FAIL "could not extract any sudoers rule from install.sh — has the way it writes them changed?"
else
    chmod 0440 /tmp/orbitra-sudoers
    RULES=$(wc -l < /tmp/orbitra-sudoers | tr -d ' ')
    TESTED=""
    VERDICT=PASS
    DETAIL=""
    for impl in sudo sudo-rs; do
        apt-cache show "$impl" >/dev/null 2>&1 || continue
        apt-get install -y -qq "$impl" >/tmp/inst-$impl.log 2>&1 || continue
        # Both packages provide visudo through alternatives, so the package
        # just installed is not necessarily the one that answers. Report the
        # implementation that actually ran, and test each one once.
        if visudo --version 2>&1 | head -n1 | grep -qi 'sudo-rs'; then
            ACTUAL=sudo-rs
        else
            ACTUAL=sudo
        fi
        case " $TESTED " in *" $ACTUAL:"*) continue ;; esac
        OUT=$(visudo -c -f /tmp/orbitra-sudoers 2>&1)
        if [ $? -eq 0 ]; then
            TESTED="$TESTED $ACTUAL:ok"
        else
            VERDICT=FAIL
            TESTED="$TESTED $ACTUAL:REJECTED"
            DETAIL="$(printf '%s' "$OUT" | grep -m1 -E 'error|not allowed' | sed 's/^ *//')"
        fi
    done
    [ -n "$TESTED" ] || TESTED=" (no sudo package on this release)"
    emit sudoers "$VERDICT" "$RULES rules;$TESTED${DETAIL:+ — $DETAIL}"
fi

# 3. Every package the installer asks for, still installable under these names.
#    Simulated, so nothing is actually unpacked: a release that renamed or
#    dropped one (php-intl and php-bcmath have both moved before) fails here
#    instead of on a user's server at step 1/5.
PKGS=$(sed -n 's/^apt-get install -y \(ca-certificates.*\)$/\1/p' "$INSTALLER" | head -n1)
if [ -z "$PKGS" ]; then
    emit packages FAIL "could not find the package list in install.sh"
elif apt-get install -s -qq $PKGS >/tmp/pkg.log 2>&1; then
    emit packages PASS "$(printf '%s' "$PKGS" | wc -w | tr -d ' ') packages resolve"
else
    emit packages FAIL "$(grep -m1 -E '^E:' /tmp/pkg.log | cut -c1-160)"
fi

# 4. Which PHP this release would actually install, and whether the installer's
#    own version parsing still understands it. PHP_V drives the FPM socket path
#    and the php${PHP_V}-fpm service name, so a surprise here (PHP 9, or a
#    changed `php -v` first line) breaks the install late and confusingly.
PHPV=$(apt-get install -s -qq php-cli 2>/dev/null | sed -n 's/^Inst php\([0-9]\+\.[0-9]\+\)-cli .*/\1/p' | head -n1)
if [ -z "$PHPV" ]; then
    emit php_version FAIL "no php-cli candidate on this release"
elif [ "${PHPV%%.*}" -ge 8 ] 2>/dev/null; then
    emit php_version PASS "php $PHPV"
else
    emit php_version FAIL "php $PHPV — below the 8.0 the panel requires"
fi
GUEST_EOF

# ------------------------------------------------------------------- backends
pick_backend() {
    if [ -n "${ORBITRA_MATRIX_BACKEND:-}" ]; then
        echo "$ORBITRA_MATRIX_BACKEND"
        return
    fi
    if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
        echo docker
        return
    fi
    if command -v debootstrap >/dev/null 2>&1 && [ "$(id -u)" -eq 0 ]; then
        echo debootstrap
        return
    fi
    echo none
}

run_docker() {
    local suite="$1" image
    image="$(suite_image "$suite")"
    # A failed pull is only fatal when the image is not already here: an
    # unreachable registry should not stop a matrix run on a machine that
    # already has the images.
    if ! docker pull -q "$image" >/dev/null 2>&1 && ! docker image inspect "$image" >/dev/null 2>&1; then
        echo "GUESTFAIL|no image $image (pull failed and none cached locally)"
        return
    fi
    docker run --rm -v "$INSTALLER:/tmp/orbitra/install.sh:ro" "$image" \
        /bin/sh -c "$GUEST_SCRIPT" 2>/dev/null
}

run_debootstrap() {
    local suite="$1" root="$CACHE_DIR/$suite" components
    # A bare debootstrap gives you main only, and the packages the installer
    # asks for are not all in main — php-fpm and friends live in universe on
    # Ubuntu. A chroot without it fails for a reason no real server has: the
    # official Docker images and every cloud image enable universe by default.
    if [ "$(suite_vendor "$suite")" = "debian" ]; then
        components="main"
    else
        components="main,universe"
    fi
    if [ ! -x "$root/bin/sh" ]; then
        mkdir -p "$CACHE_DIR"
        if ! debootstrap --variant=minbase --components="$components" \
                "$suite" "$root" "$(suite_mirror "$suite")" >"$CACHE_DIR/$suite.log" 2>&1; then
            echo "GUESTFAIL|debootstrap failed — see $CACHE_DIR/$suite.log"
            rm -rf "$root"
            return
        fi
        cp /etc/resolv.conf "$root/etc/resolv.conf" 2>/dev/null || true
        # debootstrap records only the component it bootstrapped from, whatever
        # it was told; the enabled set has to be written out explicitly.
        printf 'deb %s %s %s\n' "$(suite_mirror "$suite")" "$suite" "$(printf '%s' "$components" | tr ',' ' ')" \
            > "$root/etc/apt/sources.list"
        rm -f "$root/etc/apt/sources.list.d/"*.sources 2>/dev/null || true
    fi
    mkdir -p "$root/tmp/orbitra"
    cp "$INSTALLER" "$root/tmp/orbitra/install.sh"
    chroot "$root" /bin/sh -c "$GUEST_SCRIPT" 2>/dev/null
}

# ---------------------------------------------------------------------- report
[ -f "$INSTALLER" ] || { echo "install.sh not found at $INSTALLER" >&2; exit 2; }

BACKEND="$(pick_backend)"
if [ "$BACKEND" = none ]; then
    cat >&2 <<'MSG'
No way to run a release under test.

  * Docker: start the daemon (this is the easy path — it needs no root on
    macOS and pulls the official images itself), or
  * debootstrap: run this on Linux as root with `apt-get install debootstrap`.
MSG
    exit 2
fi

SUITES="${*:-$DEFAULT_SUITES}"
echo "Orbitra release matrix · backend: $BACKEND · $(date '+%Y-%m-%d %H:%M')"
echo

printf '%-14s %-10s %-10s %-10s %-12s\n' OS SYNTAX SUDOERS PACKAGES PHP
printf '%-14s %-10s %-10s %-10s %-12s\n' -------------- ---------- ---------- ---------- ------------

FAILURES=0
DETAILS=""
for suite in $SUITES; do
    [ "$(suite_vendor "$suite")" = unknown ] && { echo "unknown release: $suite" >&2; continue; }

    case "$BACKEND" in
        docker)      OUT="$(run_docker "$suite")" ;;
        debootstrap) OUT="$(run_debootstrap "$suite")" ;;
    esac

    if printf '%s' "$OUT" | grep -q '^GUESTFAIL|'; then
        printf '%-14s %s\n' "$(suite_pretty "$suite")" "-- could not start: $(printf '%s' "$OUT" | sed -n 's/^GUESTFAIL|//p')"
        FAILURES=$((FAILURES + 1))
        continue
    fi

    row=""
    for check in bash_syntax sudoers packages php_version; do
        line="$(printf '%s\n' "$OUT" | grep "^CHECK|$check|" | head -n1)"
        status="$(printf '%s' "$line" | cut -d'|' -f3)"
        detail="$(printf '%s' "$line" | cut -d'|' -f4-)"
        [ -n "$status" ] || status="?"
        row="$row $status"
        if [ "$status" = FAIL ]; then
            FAILURES=$((FAILURES + 1))
            DETAILS="$DETAILS
  $(suite_pretty "$suite") · $check: $detail"
        elif [ "$check" = php_version ] || [ "$check" = sudoers ]; then
            DETAILS="$DETAILS
  $(suite_pretty "$suite") · $check: $detail"
        fi
    done
    # shellcheck disable=SC2086 -- deliberate word split into the four columns
    set -- $row
    printf '%-14s %-10s %-10s %-10s %-12s\n' "$(suite_pretty "$suite")" "${1:-?}" "${2:-?}" "${3:-?}" "${4:-?}"
done

echo
echo "Details:$DETAILS"
echo
if [ "$FAILURES" -eq 0 ]; then
    echo "All checked releases can still run install.sh."
    exit 0
fi
echo "$FAILURES check(s) failed — install.sh would not work as advertised on at least one release."
exit 1
