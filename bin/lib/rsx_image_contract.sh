# The image contract for bash, sourced - never executed.
#
# The bash twin of App\RSpade\Lib\Operations\Health\Rsx_Image_Contract. Answers one
# question: is the RSpade container's image OLDER than this checkout needs? The checkout
# states what it needs in system/app/RSpade/resource/docker/image_contract.conf; the image
# carries a copy of the file it was built from at /opt/rspade/image.conf, plus a
# `built_from=<revision>` line (that file's header has the whole contract).
#
# Sourced by maintenance-mode.sh and the container entrypoint. Both may run under an image
# OLDER than this checkout, so this file uses nothing but bash, grep, sed, tr and tail, and
# needs nothing from /usr/local/bin or /opt/rspade.
#
# Applies only inside the RSpade container (the marker file exists); anywhere else every
# answer is "fine". An image with no record counts as contract 1 when it carries
# /opt/rspade/rsx-lockd/lockd-run.sh (the program contract 1 moved into the image) and
# contract 0 otherwise.
#
# Every path is an argument with the real location as its default, so a test drives these
# functions against scratch files.
#
# Usage:
#   . "$SYSTEM_DIR/bin/lib/rsx_image_contract.sh"
#   if sentence="$(rsx_image_contract_problem "$SYSTEM_DIR/app/RSpade/resource/docker/image_contract.conf")"; then
#       echo "[WARNING] $sentence"
#   fi

# The last value of <key> in a key=value file, CR and surrounding whitespace removed.
rsx_image_contract_value() {
    local file="$1" key="$2"
    sed -n "s/^[[:space:]]*${key}[[:space:]]*=//p" "$file" 2>/dev/null | tail -n1 | tr -d '\r' \
        | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//'
}

# The contract number in <file>'s `contract=` line, echoed as a plain integer. Return 1 with
# an [ERROR] line on stderr naming the file when it is absent or the value is not a whole
# number.
rsx_image_contract_number() {
    local file="$1" value

    if [ ! -r "$file" ]; then
        echo "[ERROR] $file is unreadable: the file is missing" >&2
        return 1
    fi

    value="$(rsx_image_contract_value "$file" contract)"
    case "$value" in
        ''|*[!0-9]*)
            echo "[ERROR] $file is unreadable: contract=${value}" >&2
            return 1
            ;;
    esac

    printf '%s' "$((10#$value))"
}

# rsx_image_contract_required <checkout conf> : echo the contract this checkout needs.
rsx_image_contract_required() {
    rsx_image_contract_number "$1"
}

# rsx_image_contract_problem <checkout conf> [<image record> [<container marker> [<lockd script>]]]
#
#   return 0  the image is older: print the sentence naming the remedy on stdout
#   return 1  fine, or not the RSpade container: print nothing
#   return 2  a file is malformed: an [ERROR] line naming it on stderr
rsx_image_contract_problem() {
    local conf="$1"
    local record="${2:-/opt/rspade/image.conf}"
    local marker="${3:-/.rspade_container}"
    local lockd_script="${4:-/opt/rspade/rsx-lockd/lockd-run.sh}"
    local required provided built_from target name origin

    [ -f "$marker" ] || return 1

    required="$(rsx_image_contract_required "$conf")" || return 2

    if [ -f "$record" ]; then
        provided="$(rsx_image_contract_number "$record")" || return 2
        built_from="$(rsx_image_contract_value "$record" built_from)"
        if [ -z "$built_from" ] || [ "$built_from" = "unknown" ]; then
            origin="built from an unrecorded revision"
        else
            origin="built from ${built_from}"
        fi
    else
        if [ -f "$lockd_script" ]; then
            provided=1
        else
            provided=0
        fi
        origin="built before images recorded their contract"
    fi

    [ "$provided" -lt "$required" ] || return 1

    target="$(rsx_image_contract_value "$marker" target)"
    [ -n "$target" ] || target="dev|prod"
    name="$(rsx_image_contract_value "$conf" breaking_change)"

    printf '%s\n' "This container's image (${origin}, image contract ${provided}) is older than this checkout needs (image contract ${required}): rebuild the image from this system/ (bash system/app/RSpade/resource/docker/build.sh ${target}) and recreate the container; see breaking change ${name} (php artisan rsx:framework:breaking_changes:show ${name})."
    return 0
}
