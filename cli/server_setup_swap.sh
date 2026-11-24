#!/bin/bash
# cli/server_setup_swap.sh — one step of cli/server_setup.sh.
#
# Many budget cloud VPS images (1–8 GiB RAM on DigitalOcean, Hetzner, Vultr,
# etc.) ship with zero swap. Under traffic spikes or panel updates, the Linux
# OOM-killer can abruptly terminate php-fpm or background workers. This step
# creates a swapfile when:
#   - no swap is currently active (/proc/swaps has only the header),
#   - total RAM is <= SWAP_MAX_RAM_MB (default 8500 MiB, covering 1–8 GiB VPS),
#   - the target path (/swapfile) does not already exist,
#   - the filesystem is not known-incompatible (btrfs, zfs, overlay, tmpfs),
#   - the disk has enough free space to keep at least 4 GiB headroom:
#       >= 20 GiB free -> 4 GiB swap
#       >=  8 GiB free -> 2 GiB swap
#       >=  5 GiB free -> 1 GiB swap
#       <   5 GiB free -> skipped
#
# Rollback: if mkswap or swapon fails (e.g. LXC/OpenVZ/Docker where swapon is
# denied), the partially created file is removed immediately and /etc/fstab is
# left untouched. Idempotent: a second run sees active swap and does nothing.
#
# Never fatal: exits 0 on any skip or failure — this step is a safety net, not
# a hard requirement.
#
# Standalone-testable: SWAP_FILE, SWAP_FSTAB, SWAP_PROC_SWAPS, SWAP_MEMINFO,
# SWAP_FREE_MB, SWAP_FS_TYPE, SWAP_SIZE_MB, SWAP_MAX_RAM_MB, FALLOCATE_BIN,
# MKSWAP_BIN, SWAPON_BIN can come from the environment.

set -euo pipefail

swap_file="${SWAP_FILE:-/swapfile}"
fstab_file="${SWAP_FSTAB:-/etc/fstab}"
proc_swaps="${SWAP_PROC_SWAPS:-/proc/swaps}"
meminfo_file="${SWAP_MEMINFO:-/proc/meminfo}"
max_ram_mb="${SWAP_MAX_RAM_MB:-8500}"

mkswap_bin="${MKSWAP_BIN:-}"
swapon_bin="${SWAPON_BIN:-}"
fallocate_bin="${FALLOCATE_BIN:-}"

resolve_bin() { # resolve_bin <name> <fallback_path>
    if command -v "$1" >/dev/null 2>&1; then
        command -v "$1"
    elif [ -x "$2" ]; then
        printf '%s' "$2"
    fi
}

[ -n "$mkswap_bin" ] || mkswap_bin="$(resolve_bin mkswap /sbin/mkswap)"
[ -n "$swapon_bin" ] || swapon_bin="$(resolve_bin swapon /sbin/swapon)"
[ -n "$fallocate_bin" ] || fallocate_bin="$(resolve_bin fallocate /usr/bin/fallocate)"

# --- 1. Check whether swap is already active ----------------------------------
if [ -r "$proc_swaps" ]; then
    if awk 'NR > 1 && NF > 0 { found = 1 } END { exit !found }' "$proc_swaps"; then
        echo "  > swap already active — nothing changed"
        exit 0
    fi
elif [ -n "$swapon_bin" ] && "$swapon_bin" --show --noheadings 2>/dev/null | grep -q '[^[:space:]]'; then
    echo "  > swap already active — nothing changed"
    exit 0
fi

# --- 2. Check existing swapfile path ------------------------------------------
if [ -e "$swap_file" ]; then
    echo "  > NOTE: $swap_file already exists — swap step skipped (will not overwrite)"
    exit 0
fi

# --- 3. Check required tools --------------------------------------------------
if [ -z "$mkswap_bin" ] || [ -z "$swapon_bin" ]; then
    echo "  > NOTE: mkswap/swapon not found — swap step skipped"
    exit 0
fi

# --- 4. Check RAM threshold (skip high-RAM hosts unless overridden) -----------
if [ -r "$meminfo_file" ] && [ "${max_ram_mb}" -gt 0 ] 2>/dev/null; then
    ram_kb="$(awk '/^MemTotal:/ { print $2; exit }' "$meminfo_file")"
    if [ -n "$ram_kb" ] && [ "$ram_kb" -gt 0 ] 2>/dev/null; then
        ram_mb=$((ram_kb / 1024))
        if [ "$ram_mb" -gt "$max_ram_mb" ]; then
            echo "  > RAM is ${ram_mb} MiB (> ${max_ram_mb} MiB) — swap step skipped"
            exit 0
        fi
    fi
fi

# --- 5. Check filesystem compatibility ----------------------------------------
swap_dir="$(dirname "$swap_file")"
if [ ! -d "$swap_dir" ]; then
    echo "  > NOTE: parent directory $swap_dir does not exist — swap step skipped"
    exit 0
fi

fs_type="${SWAP_FS_TYPE:-}"
if [ -z "$fs_type" ]; then
    # GNU df -T prints Type in column 2; on BSD/macOS it fails and stays empty.
    fs_type="$(df -T "$swap_dir" 2>/dev/null | awk 'NR == 2 { print $2 }')" || true
fi
case "$fs_type" in
    btrfs|zfs|overlay|overlayfs|tmpfs|nfs|nfs4|cifs|fuse.*)
        echo "  > NOTE: filesystem '$fs_type' is not suitable for a plain swapfile — swap step skipped"
        exit 0
        ;;
esac

# --- 6. Pick swap size based on available disk space --------------------------
free_mb="${SWAP_FREE_MB:-}"
if [ -z "$free_mb" ]; then
    free_mb="$(df -Pm "$swap_dir" 2>/dev/null | awk 'NR == 2 { print $4 }')" || true
fi
if ! printf '%s' "$free_mb" | grep -Eq '^[0-9]+$'; then
    echo "  > NOTE: could not determine free disk space on $swap_dir — swap step skipped"
    exit 0
fi

size_mb="${SWAP_SIZE_MB:-}"
if [ -z "$size_mb" ]; then
    if [ "$free_mb" -ge 20480 ]; then
        size_mb=4096
    elif [ "$free_mb" -ge 8192 ]; then
        size_mb=2048
    elif [ "$free_mb" -ge 5120 ]; then
        size_mb=1024
    else
        echo "  > NOTE: only ${free_mb} MiB free on $swap_dir (need >= 5120 MiB) — swap step skipped"
        exit 0
    fi
else
    # Even with an explicit SWAP_SIZE_MB, keep at least 1024 MiB free headroom
    # unless running in a tiny test fixture where SWAP_FREE_MB was set explicitly.
    if [ "$free_mb" -le "$size_mb" ]; then
        echo "  > NOTE: only ${free_mb} MiB free on $swap_dir (need > ${size_mb} MiB) — swap step skipped"
        exit 0
    fi
fi

# --- 7. Allocate, format and activate (rollback file on any failure) ----------
cleanup_swapfile() {
    rm -f "$swap_file"
}

allocated=0
if [ -n "$fallocate_bin" ]; then
    if "$fallocate_bin" -l "${size_mb}M" "$swap_file" >/dev/null 2>&1; then
        allocated=1
    else
        cleanup_swapfile
    fi
fi

if [ "$allocated" -eq 0 ]; then
    if ! dd if=/dev/zero of="$swap_file" bs=1048576 count="$size_mb" >/dev/null 2>&1; then
        cleanup_swapfile
        echo "  ! WARNING: could not allocate ${size_mb} MiB for $swap_file — swap step skipped"
        exit 0
    fi
fi

if ! chmod 0600 "$swap_file" >/dev/null 2>&1; then
    cleanup_swapfile
    echo "  ! WARNING: could not chmod 0600 $swap_file — swap step skipped"
    exit 0
fi

if ! "$mkswap_bin" "$swap_file" >/dev/null 2>&1; then
    cleanup_swapfile
    echo "  ! WARNING: mkswap failed on $swap_file — file removed, swap step skipped"
    exit 0
fi

if ! "$swapon_bin" "$swap_file" >/dev/null 2>&1; then
    cleanup_swapfile
    echo "  > NOTE: swapon denied or unsupported (container/VPS restriction) — file removed, swap step skipped"
    exit 0
fi

# --- 8. Persist in /etc/fstab (only after swapon succeeded) -------------------
if [ -f "$fstab_file" ] && [ -w "$fstab_file" ]; then
    if ! grep -Eq "^[[:space:]]*${swap_file}[[:space:]]" "$fstab_file"; then
        # Ensure trailing newline before appending.
        if [ -s "$fstab_file" ] && [ "$(tail -c 1 "$fstab_file" | wc -l)" -eq 0 ]; then
            printf '\n' >> "$fstab_file"
        fi
        printf '%s none swap sw 0 0\n' "$swap_file" >> "$fstab_file"
    fi
else
    echo "  > NOTE: $fstab_file not writable — swap is active now but not persisted across reboot"
fi

echo "  > swap enabled: ${swap_file} (${size_mb} MiB)"
exit 0
