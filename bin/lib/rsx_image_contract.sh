# The minimum image version for bash, sourced - never executed.
#
# The bash twin of system/bootstrap/rsx_image_contract.php (and the class over it,
# App\RSpade\Lib\Operations\Health\Rsx_Image_Contract). Answers one question: is the RSpade
# container's image OLDER than this checkout needs? The checkout states the oldest image it
# runs on as `min_image_version=` in system/app/RSpade/resource/docker/image_contract.conf
# (that file's header has the rule for moving it); an image records what it is at
# /opt/rspade/image.conf as `image_version=` (with `built_from=`).
#
# THE COMPARISON. Versions are dated, YYYY.MM.DD with an optional .N, compared numerically
# field by field (a missing .N is 0). An image recording `image_version=local` was built from
# a checkout's own sources (build.sh) and always passes. An image with no record, or a record
# with no image_version or one that is not a dated version, FAILS.
#
# SKIPPED, every answer "fine": outside the RSpade container (no marker file), and on the
# framework authors' own box (IS_FRAMEWORK_DEVELOPER true in the environment or the
# project's .env), whose image is built on the host and carries no record.
#
# Sourced by the container entrypoint (fatal), maintenance-mode.sh (a warning - it is the
# boot-free lever), the service wait library and the framework updater's pre-check. All of
# them may run under an image OLDER than this checkout, so this file uses nothing but bash,
# grep, sed, tr and tail, and needs nothing from /usr/local/bin or /opt/rspade.
#
# Every path is an argument with the real location as its default, so a test drives these
# functions against scratch files.
#
# Usage:
#   . "$SYSTEM_DIR/bin/lib/rsx_image_contract.sh"
#   if sentence="$(rsx_image_contract_problem "$SYSTEM_DIR/app/RSpade/resource/docker/image_contract.conf")"; then
#       echo "[ERROR] $sentence"
#   fi

# The last value of <key> in a key=value file, CR and surrounding whitespace removed.
rsx_image_contract_value() {
    local file="$1" key="$2"
    sed -n "s/^[[:space:]]*${key}[[:space:]]*=//p" "$file" 2>/dev/null | tail -n1 | tr -d '\r' \
        | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//'
}

# rsx_image_contract_version_fields <version> : echo "Y M D N" as plain integers for a dated
# version (YYYY.MM.DD or YYYY.MM.DD.N; a missing N is 0). Return 1 for anything else.
rsx_image_contract_version_fields() {
    local version="$1" y m d n

    printf '%s' "$version" | grep -qE '^[0-9]{4}\.[0-9]{2}\.[0-9]{2}(\.[0-9]+)?$' || return 1

    IFS=. read -r y m d n <<< "$version"
    # 10#: "08" and "09" are decimal here, never an invalid octal.
    printf '%s %s %s %s' "$((10#$y))" "$((10#$m))" "$((10#$d))" "$((10#${n:-0}))"
}

# rsx_image_contract_version_older <a> <b> : return 0 when dated version <a> is older than
# dated version <b>, 1 otherwise. Both must be dated versions (version_fields).
rsx_image_contract_version_older() {
    local a b i
    read -r -a a <<< "$(rsx_image_contract_version_fields "$1")"
    read -r -a b <<< "$(rsx_image_contract_version_fields "$2")"

    for i in 0 1 2 3; do
        [ "${a[$i]}" -lt "${b[$i]}" ] && return 0
        [ "${a[$i]}" -gt "${b[$i]}" ] && return 1
    done

    return 1
}

# rsx_image_contract_required <checkout conf> : echo the minimum image version this checkout
# needs. Return 1 with an [ERROR] line on stderr naming the file when it is absent or its
# min_image_version is not a dated version.
rsx_image_contract_required() {
    local file="$1" value

    if [ ! -r "$file" ]; then
        echo "[ERROR] $file is unreadable: the file is missing" >&2
        return 1
    fi

    value="$(rsx_image_contract_value "$file" min_image_version)"
    if ! rsx_image_contract_version_fields "$value" >/dev/null; then
        echo "[ERROR] $file is unreadable: min_image_version=${value}" >&2
        return 1
    fi

    printf '%s' "$value"
}

# rsx_image_contract_framework_developer [<env file>] : return 0 when IS_FRAMEWORK_DEVELOPER
# is true (1/true/yes/on, any case) - the environment when set and non-empty, else the first
# occurrence in <env file> (default: the project's .env, through rsx_paths.sh when sourced,
# else the container's /var/www/html/.env).
rsx_image_contract_framework_developer() {
    local env_file="${1:-}" value line

    if [ -z "$env_file" ]; then
        if declare -F rsx_paths_project_root >/dev/null; then
            env_file="$(rsx_paths_project_root)/.env"
        else
            env_file=/var/www/html/.env
        fi
    fi

    value="${IS_FRAMEWORK_DEVELOPER:-}"
    if [ -z "$value" ] && [ -f "$env_file" ]; then
        line="$(grep -m1 -E '^[[:space:]]*IS_FRAMEWORK_DEVELOPER[[:space:]]*=' "$env_file" 2>/dev/null)" || true
        value="$(printf '%s' "${line#*=}" | tr -d '\r"'"'" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    fi

    case "$(printf '%s' "$value" | tr '[:upper:]' '[:lower:]')" in
        1|true|yes|on) return 0 ;;
    esac

    return 1
}

# rsx_image_contract_problem <checkout conf> [<image record> [<container marker> [<env file>]]]
#
#   return 0  the image is older: print the sentence naming the remedy on stdout
#   return 1  fine, or skipped (not the RSpade container, the framework authors' box)
#   return 2  the checkout's file is malformed: an [ERROR] line naming it on stderr
rsx_image_contract_problem() {
    local conf="$1"
    local record="${2:-/opt/rspade/image.conf}"
    local marker="${3:-/.rspade_container}"
    local env_file="${4:-}"
    local required provided origin target

    [ -f "$marker" ] || return 1
    rsx_image_contract_framework_developer "$env_file" && return 1

    required="$(rsx_image_contract_required "$conf")" || return 2

    if [ -f "$record" ]; then
        provided="$(rsx_image_contract_value "$record" image_version)"
    else
        provided=''
    fi

    [ "$provided" = "local" ] && return 1

    if [ -z "$provided" ]; then
        origin="no recorded version"
    elif ! rsx_image_contract_version_fields "$provided" >/dev/null; then
        origin="version ${provided}, not a dated version"
    elif rsx_image_contract_version_older "$provided" "$required"; then
        origin="version ${provided}"
    else
        return 1
    fi

    target="$(rsx_image_contract_value "$marker" target)"
    [ -n "$target" ] || target="<dev|prod>"

    printf '%s\n' "This container's image (${origin}) is older than this checkout needs (${required} or newer): docker pull ghcr.io/rspade-framework/rspade-server-${target}:latest, then recreate the container from it."
    return 0
}
