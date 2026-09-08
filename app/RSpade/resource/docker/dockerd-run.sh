#!/bin/bash
#
# Supervisor runner for the container's OWN Docker Engine (docker-in-docker).
#
# WHY THERE IS A DAEMON IN HERE AT ALL. `php artisan rsx:test` dispatches every
# invocation to sibling containers when its gate passes, and the gate's second
# check is "does `docker info` answer". That daemon is this one. See
# `rsx:man testing`.
#
# Everything before the exec is PREFLIGHT. Every failure mode of nested docker
# is "the container was started without X", and each one surfaces as an opaque
# error deep inside dockerd or runc. Checked here, they are one line.
#
# ON A FAILED PREFLIGHT THIS EXITS 0, QUIETLY-ish: one line saying what is
# missing, and then nothing. A container started without the capabilities is not
# broken - it is a container that cannot run nested docker, which is an ordinary
# and supported way to run RSpade. The test runner's gate notices and runs the
# suite sequentially. The supervisor program is declared autorestart=false for
# the same reason: there is nothing here to retry, and a restart loop would fill
# the container log with a condition that cannot change while it runs.
#
# NO TIMEOUT, NO SLEEP, NO POLL. This either execs dockerd or returns.
#
set -u

log() { echo "[dockerd] $*" >&2; }

# Already answering (a hand-started daemon, or a restart of this program while
# one is alive): nothing to do, and starting a second one would fight it.
if docker info >/dev/null 2>&1; then
    log "a docker daemon is already answering; nothing to start."
    exit 0
fi

# --- Capability preflight ----------------------------------------------------
#
# CapEff is a 64-bit hex mask in /proc/self/status; the bit numbers are the
# values in <linux/capability.h>. Only the two that actually gate dockerd are
# checked, because they are the two an ordinary container is started without.
cap_eff_hex=$(awk '/^CapEff:/ { print $2 }' /proc/self/status)
cap_eff=$(( 16#${cap_eff_hex:-0} ))
has_cap() { (( (cap_eff >> $1) & 1 )); }

CAP_NET_ADMIN=12
CAP_SYS_ADMIN=21

missing=''
has_cap $CAP_NET_ADMIN || missing="$missing CAP_NET_ADMIN(no NAT chain: bridge networking fails at startup)"
has_cap $CAP_SYS_ADMIN || missing="$missing CAP_SYS_ADMIN(runc cannot bind-mount: no image can be extracted or run)"

if [ -n "$missing" ]; then
    log "not starting - this container lacks:$missing. Start it with the capability set in 'rsx:man testing' to run the test suite in containers (CapEff=$cap_eff_hex)."
    exit 0
fi

# --- Writable cgroup2 --------------------------------------------------------
#
# Docker mounts /sys/fs/cgroup read-only, and runc creates a cgroup for every
# container it starts - without a writable cgroupfs every `docker run` dies with
# "unable to apply cgroup configuration: mkdir /sys/fs/cgroup/docker: read-only
# file system".
#
# Two ways out, tried in order:
#
#   1. remount,rw - the cheap path. It works under --privileged and fails on a
#      stock kernel, where the mount is inherited from the host's mount
#      namespace with its ro flag LOCKED, so CAP_SYS_ADMIN is not enough.
#
#   2. Mount a FRESH cgroup2 superblock and bind it over the read-only one. This
#      is the good outcome rather than a workaround: a cgroup2 mount created
#      inside a private cgroup namespace (what docker gives a container by
#      default on a cgroup v2 host - /proc/self/cgroup reads '0::/') is rooted at
#      the container's OWN cgroup, so the daemon gets write access to its subtree
#      and to nothing above it. Isolation is preserved; the alternatives in
#      circulation - --privileged, or bind-mounting the host's cgroupfs rw - hand
#      over the entire host hierarchy instead.
#
#      It has to be staged at a scratch path first: mounting cgroup2 directly
#      over /sys/fs/cgroup returns EBUSY (same superblock, same mountpoint), and
#      unmounting the original first fails with "target is busy".
ensure_writable_cgroup2() {
    [ "$(stat -fc %T /sys/fs/cgroup 2>/dev/null)" = "cgroup2fs" ] || return 0
    [ -w /sys/fs/cgroup ] && return 0

    mount -o remount,rw /sys/fs/cgroup 2>/dev/null && return 0

    local staging=/run/rspade-cgroup2
    mkdir -p "$staging"
    if mount -t cgroup2 none "$staging" 2>/dev/null && mount --bind "$staging" /sys/fs/cgroup 2>/dev/null; then
        log "mounted a namespace-scoped writable cgroup2 over /sys/fs/cgroup."
        return 0
    fi

    return 1
}

if ! ensure_writable_cgroup2; then
    log "not starting - /sys/fs/cgroup is read-only and could not be made writable, so runc could not start a single container. See the confinement flags in 'rsx:man testing'."
    exit 0
fi

mkdir -p /var/lib/docker

log "starting dockerd."
exec dockerd "$@"
