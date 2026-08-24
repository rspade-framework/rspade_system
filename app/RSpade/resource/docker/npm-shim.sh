#!/bin/bash
#
# npm, routed through the RSpade application dependency layer.
#
# Installed as /usr/local/bin/npm inside the RSpade container, which comes first
# on PATH - so `npm install foo` means `php artisan rsx:npm install foo` without
# anybody having to remember that. The real binary is still at /usr/bin/npm.
#
# WHY THE WRAPPER EXISTS. RSpade has TWO npm layers and they are not
# interchangeable. system/package.json is the FRAMEWORK's, committed and shipped
# by rsx:framework:pull; the project-root package.json is the APPLICATION's,
# where an app's own packages belong. Bare `npm install foo` at the project root
# gets the second one roughly right by accident, but skips everything rsx:npm
# exists to do: refusing framework-internal packages, and RECORDING a package the
# framework already provides instead of installing a second copy of it. Getting
# that wrong is silent - you end up with a duplicate dependency that shadows
# nothing (Node resolves system/node_modules first) and drifts from the
# framework's version forever.
#
# FOUR WAYS THIS GETS OUT OF THE WAY, because a wrapper that traps you is worse
# than no wrapper:
#
#   1. RE-ENTRANCY. rsx:npm runs real npm through Symfony Process as `npm`, which
#      resolves through PATH and would come straight back here - forever. The
#      marker is exported before handing off, and seeing it means exec the real
#      binary immediately. This one is not a convenience; without it the wrapper
#      is an infinite loop.
#
#   2. INSIDE system/. The framework's own dependencies are installed with plain
#      npm inside system/, and rsx:npm explicitly never operates on
#      system/package.json. A shim that intercepted there would make framework
#      dependency work impossible - so cwd under system/ is real npm, always.
#      (This case has no equivalent in the git shim, whose proxy DOES want to
#      intervene everywhere in the project.)
#
#   3. OUTSIDE THE PROJECT. Working on some other package in this container is
#      none of RSpade's business.
#
#   4. NO WORKING PHP. rsx:npm runs through artisan. If php is missing or the
#      tree is broken enough that it will not start, npm still has to work - that
#      is usually exactly when you need it, because npm is how you fix it.
#
set -u

REAL_NPM=/usr/bin/npm
PROJECT_ROOT="${RSPADE_PROJECT_ROOT:-/var/www/html}"
ARTISAN="$PROJECT_ROOT/artisan"

# 1. Already inside rsx:npm: this is rsx:npm asking for real npm.
if [ -n "${RSX_NPM_SHIM_ACTIVE:-}" ]; then
    exec "$REAL_NPM" "$@"
fi

# 2. Framework dependency work happens inside system/ with plain npm.
case "$PWD/" in
    "$PROJECT_ROOT"/system/*) exec "$REAL_NPM" "$@" ;;
esac

# 3. Not working inside the project.
case "$PWD/" in
    "$PROJECT_ROOT"/*) ;;
    *) exec "$REAL_NPM" "$@" ;;
esac

# 4. Nothing to route through.
if [ ! -f "$ARTISAN" ] || ! command -v php >/dev/null 2>&1; then
    exec "$REAL_NPM" "$@"
fi

export RSX_NPM_SHIM_ACTIVE=1
exec php "$ARTISAN" rsx:npm "$@"
