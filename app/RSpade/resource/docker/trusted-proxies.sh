#!/bin/bash
# rspade-trusted-proxies - tell nginx and php-fpm which proxies sit in front of the container.
#
# Invoke as: bash rspade-trusted-proxies apply [--root=DIR] [--env-file=PATH]
#
# THE PROBLEM. Behind a reverse proxy (Caddy, a load balancer), every request reaches nginx
# from the proxy's address. nginx's access log then names only the proxy, and so does the
# REMOTE_ADDR it hands php-fpm. The application already resolves the real client from
# X-Forwarded-For for the proxies .env TRUSTED_PROXIES declares; the web server did not.
#
# THE RULE - one effective list, the same for nginx and the application:
#
#     .env TRUSTED_PROXIES      container RSPADE_TRUSTED_PROXIES      effective
#     set                       any                                   .env's
#     blank                     set                                   the container's
#     blank                     blank                                 loopback only
#
# An explicit application setting wins; a deployment declares its proxy once, in the
# container's environment (the compose file), and .env need not repeat it. The container
# variable is NOT named TRUSTED_PROXIES: a real environment variable overrides .env in
# Laravel, so wherever it was visible (the CLI) it would reverse that precedence.
#
# WHAT IT WRITES, at every container start:
#
#   - /etc/nginx/conf.d/rspade-realip.conf, ALWAYS, empty value included. The container's
#     filesystem survives a restart, so a file left by an earlier start would otherwise
#     keep trusting a proxy nobody declares any more. Loopback is always listed: a hop
#     from inside the container (a proxy_pass back to nginx) resolves to the client too.
#     real_ip_recursive walks X-Forwarded-For right to left past trusted hops - the rule
#     the application's TrustProxies middleware uses.
#
#   - env[RSPADE_TRUSTED_PROXIES] in the php-fpm pool, the CONTAINER value, written as a
#     literal. A pool has clear_env on by default, so php-fpm workers never see the
#     container's environment otherwise; the application applies the .env precedence
#     itself (config('rsx.http.trusted_proxies')), so the .env key stays authoritative in
#     every context, the CLI included.
#
# EVERY TOKEN IS VALIDATED before it is written: the value is pasted into nginx's
# configuration, so anything but an address or a CIDR - a ';', a newline - would be
# configuration injection. A /0 range (0.0.0.0/0, ::/0) is refused: trusting every address
# lets any client name its own, 127.0.0.1 included, which defeats the login throttle and
# every check that a request came from the box itself.
#
# --root and --env-file are the test seam: every path written is under --root (default /),
# and --env-file names the .env to read (default /var/www/html/.env).

set -u

ROOT="/"
ENV_FILE="/var/www/html/.env"
PHP_VERSION="8.5"

die() {
    echo "[rspade-trusted-proxies] ERROR: $*" >&2
    exit 1
}

# The last NON-EMPTY definition of a key in the .env, as the entrypoint's env_value reads it.
env_file_value() {
    [ -f "$ENV_FILE" ] || return 0
    sed -n "s/^$1=//p" "$ENV_FILE" | grep -v '^[[:space:]]*$' | tail -n1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

# One token: an IPv4 or IPv6 address, optionally with a prefix length. Prints the token
# or dies naming it.
validate_token() {
    local token="$1" address prefix=""

    address="${token%%/*}"
    if [ "$address" != "$token" ]; then
        prefix="${token#*/}"
        [[ "$prefix" =~ ^[0-9]{1,3}$ ]] || die "'$token' is not an address or a CIDR range."
        [ "$prefix" -gt 0 ] || die "'$token' trusts every address, so any client could claim any IP - 127.0.0.1 included. Name the proxy's own address."
    fi

    if [[ "$address" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]]; then
        local octet
        for octet in "${BASH_REMATCH[@]:1}"; do
            [ "$octet" -le 255 ] || die "'$token' is not an IPv4 address."
        done
        [ -z "$prefix" ] || [ "$prefix" -le 32 ] || die "'$token': an IPv4 prefix is at most 32."
    elif [[ "$address" == *:* && "$address" =~ ^[0-9A-Fa-f:.]+$ ]]; then
        [ -z "$prefix" ] || [ "$prefix" -le 128 ] || die "'$token': an IPv6 prefix is at most 128."
    else
        die "'$token' is not an address or a CIDR range. Use comma-separated addresses, e.g. 172.18.0.3,10.0.0.0/8."
    fi

    printf '%s\n' "$token"
}

# A comma-separated list -> validated tokens, one per line, blanks dropped.
validate_list() {
    local list="$1" token
    local IFS=','
    for token in $list; do
        token="${token//[[:space:]]/}"
        [ -n "$token" ] || continue
        validate_token "$token" || exit 1
    done
}

apply() {
    local from_env from_container effective source tokens container_tokens
    from_env="$(env_file_value TRUSTED_PROXIES)"
    from_container="${RSPADE_TRUSTED_PROXIES:-}"

    if [ -n "${from_env//[[:space:],]/}" ]; then
        effective="$from_env"; source=".env"
    elif [ -n "${from_container//[[:space:],]/}" ]; then
        effective="$from_container"; source="container"
    else
        effective=""; source="none"
    fi

    tokens="$(validate_list "$effective")" || exit 1
    container_tokens="$(validate_list "$from_container")" || exit 1

    local conf_dir="${ROOT%/}/etc/nginx/conf.d"
    mkdir -p "$conf_dir" || die "could not create $conf_dir"
    {
        echo "# Generated by rspade-trusted-proxies at container start. Do not edit."
        echo "# Source: .env TRUSTED_PROXIES, else container RSPADE_TRUSTED_PROXIES (here: ${source})."
        echo "set_real_ip_from 127.0.0.1;"
        echo "set_real_ip_from ::1;"
        local token
        while IFS= read -r token; do
            [ -n "$token" ] && echo "set_real_ip_from ${token};"
        done <<< "$tokens"
        echo "real_ip_header   X-Forwarded-For;"
        echo "real_ip_recursive on;"
    } > "$conf_dir/rspade-realip.conf" || die "could not write $conf_dir/rspade-realip.conf"

    local literal pool
    literal="$(printf '%s' "$container_tokens" | paste -sd, -)"
    pool="${ROOT%/}/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
    if [ -f "$pool" ]; then
        sed -i '/^env\[RSPADE_TRUSTED_PROXIES\]/d' "$pool" || die "could not edit $pool"
        if [ -n "$literal" ]; then
            printf 'env[RSPADE_TRUSTED_PROXIES] = "%s"\n' "$literal" >> "$pool" || die "could not edit $pool"
        fi
    fi

    if [ "$source" = "none" ]; then
        echo "Trusted proxies: loopback only (none declared)"
    else
        echo "Trusted proxies: $(printf '%s' "$tokens" | paste -sd, -) (from ${source})"
    fi
}

command="${1:-}"
shift || true
for argument in "$@"; do
    case "$argument" in
        --root=*) ROOT="${argument#--root=}" ;;
        --env-file=*) ENV_FILE="${argument#--env-file=}" ;;
        *) die "unknown argument '$argument'" ;;
    esac
done

case "$command" in
    apply) apply ;;
    *) die "usage: bash rspade-trusted-proxies apply [--root=DIR] [--env-file=PATH]" ;;
esac
