# RSpade path resolution for bash, sourced - never executed.
#
# The bash twin of system/bootstrap/rsx_paths.php. Every script that needs to know
# where the build tree, the tmp tree, storage or the state directory live sources
# this instead of composing "$PROJECT_ROOT/storage/..." by hand, so an operator who
# relocated a root with RSX_TMP_PATH or RSX_STORAGE_PATH gets the same answer from a
# shell script that PHP gives. build/ is fixed at <project>/build and has no key.
#
# Reading order matches phpdotenv's immutable behaviour and the PHP resolver: a
# non-empty real environment variable wins, otherwise the FIRST matching line of the
# project-root .env. PHP that spawns a script exports the resolved roots first, so a
# child normally reads them straight out of its environment.
#
# Usage:
#   RSX_PATHS_PROJECT_ROOT_DIR="$PROJECT_ROOT"      # optional; default is derived
#   . "$SYSTEM_DIR/bin/lib/rsx_paths.sh"
#   flag="$(rsx_state_root)/.maintenance.mode.framework.update"

# The project root: the directory holding system/, rsx/, build/, tmp/, storage/.
rsx_paths_project_root() {
    if [ -n "${RSX_PATHS_PROJECT_ROOT_DIR:-}" ]; then
        printf '%s' "${RSX_PATHS_PROJECT_ROOT_DIR%/}"
        return 0
    fi

    # ${BASH_SOURCE[0]} is <project>/system/bin/lib/rsx_paths.sh.
    local lib_dir
    lib_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    printf '%s' "$(dirname "$(dirname "$(dirname "$lib_dir")")")"
}

# One key: the environment when set and non-empty, else the first .env occurrence.
# A matched pair of surrounding quotes is stripped; nothing cleverer.
rsx_env_value() {
    local key="$1" fallback="${2:-}" env_file line value

    value="$(printf '%s' "${!key:-}")"
    if [ -n "$value" ]; then
        printf '%s' "$value"
        return 0
    fi

    env_file="$(rsx_paths_project_root)/.env"
    [ -f "$env_file" ] || { printf '%s' "$fallback"; return 0; }

    line="$(grep -m1 -E "^[[:space:]]*${key}[[:space:]]*=" "$env_file" 2>/dev/null)" || true
    [ -n "$line" ] || { printf '%s' "$fallback"; return 0; }

    value="${line#*=}"
    value="$(printf '%s' "$value" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    value="${value%\"}"; value="${value#\"}"
    value="${value%\'}"; value="${value#\'}"

    [ -n "$value" ] || { printf '%s' "$fallback"; return 0; }
    printf '%s' "$value"
}

# A relative override resolves against the PROJECT ROOT: a subprocess started from an
# unknown working directory would otherwise resolve a different tree than its parent.
rsx_paths_resolve_root() {
    local key="$1" default_name="$2" value
    value="$(rsx_env_value "$key")"

    if [ -z "$value" ]; then
        printf '%s' "$(rsx_paths_project_root)/$default_name"
        return 0
    fi

    # A relative value is relative to the PROJECT ROOT, never to the working directory.
    case "$value" in
        /*) printf '%s' "${value%/}" ;;
        *)  value="${value#./}"; printf '%s' "$(rsx_paths_project_root)/${value%/}" ;;
    esac
}

# build/ is FIXED beside system/ and rsx/ - the seal, the manifest index and the build
# key live in it, and it is made read-only with them on a production box.
rsx_build_root()   { printf '%s' "$(rsx_paths_project_root)/build"; }
rsx_tmp_root()     { rsx_paths_resolve_root RSX_TMP_PATH tmp; }
rsx_storage_root() { rsx_paths_resolve_root RSX_STORAGE_PATH storage; }
rsx_state_root()   { printf '%s' "$(rsx_storage_root)/state"; }

# The resolved roots, for the processes a script starts. They read the same absolutes
# their parent resolved instead of re-deriving them from a working directory.
#
# TMPDIR IS DELIBERATELY NOT SET HERE. PHP points sys_get_temp_dir() at the tmp tree
# itself, in the first lines of both entrypoints, so every PHP process - CLI and fpm
# worker alike - already has it. Exporting it to a whole process tree instead hands it
# to every other service the supervisor starts, and mysqld reads TMPDIR for its own
# temporary tables: pointed at a project directory its user cannot write, it refuses to
# start, which is a database outage bought for nothing.
rsx_export_paths() {
    RSX_TMP_PATH="$(rsx_tmp_root)"
    RSX_STORAGE_PATH="$(rsx_storage_root)"
    export RSX_TMP_PATH RSX_STORAGE_PATH
}
