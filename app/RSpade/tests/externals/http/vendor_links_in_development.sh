#!/bin/bash
set -e

TEST_NAME="Vendor links in development"

# HTTP integration test - runs against the live web server in DEVELOPMENT mode. It is the
# web-branch counterpart to Externals_Resolver_Test and Vendor_Route_Test, and it proves the
# one thing only a real page can: that a DEVELOPMENT box serves the page a sealed box serves.
#
#   1. Every external asset the page links is a /_vendor/ name of the mirror's own shape -
#      no CDN host survives anywhere in the document.
#   2. The Content-Security-Policy therefore names no CDN and no font host: a mirrored asset
#      is same-origin and contributes nothing to the policy in any mode.
#   3. The /_vendor/ route actually serves those files, with the right content type - a css
#      link the page emitted, and the bootstrap-icons woff2 that a localized stylesheet's
#      @font-face names (the reference that used to reach jsdelivr at render time).

BASE="http://localhost"
STORE="/var/www/html/rsx/resource/.cdn-cache"
BODY="/tmp/vendor_links_$$.html"

cleanup() { rm -f "$BODY"; }
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Step 1: every /_vendor/ link on the page carries a store-shaped name.
# ---------------------------------------------------------------------------
echo "[TEST] 1. GET /login links every external asset under /_vendor/..." >&2
headers="$(curl -s -D - -o "$BODY" "${BASE}/login" 2>/dev/null)"

vendor_refs="$(grep -oE '(href|src)="/_vendor/[^"]+"' "$BODY" | sed 's/.*"\/_vendor\///; s/"$//' || true)"

if [ -z "$vendor_refs" ]; then
    echo "FAIL: $TEST_NAME - /login linked nothing under /_vendor/"
    exit 1
fi

while IFS= read -r name; do
    if ! printf '%s' "$name" | grep -qE '^[a-f0-9]{32}_'; then
        echo "FAIL: $TEST_NAME - /_vendor/ link '$name' does not carry the md5 store name"
        exit 1
    fi
done <<< "$vendor_refs"
echo "[TEST] 1. OK - $(printf '%s\n' "$vendor_refs" | wc -l) mirrored links, all store-shaped" >&2

# ---------------------------------------------------------------------------
# Step 2: no CDN host survives in the document.
# ---------------------------------------------------------------------------
echo "[TEST] 2. The page names no CDN host..." >&2
if grep -q 'cdn.jsdelivr.net' "$BODY"; then
    echo "FAIL: $TEST_NAME - the page still names cdn.jsdelivr.net"
    grep -o 'https://cdn.jsdelivr.net[^"'"'"']*' "$BODY" | head -5
    exit 1
fi
echo "[TEST] 2. OK - no CDN host in the document" >&2

# ---------------------------------------------------------------------------
# Step 3: the policy names no CDN and no font host either.
# ---------------------------------------------------------------------------
echo "[TEST] 3. The Content-Security-Policy names no mirrored origin..." >&2
policy="$(printf '%s' "$headers" | grep -i '^content-security-policy:' | head -1 || true)"

if [ -z "$policy" ]; then
    echo "FAIL: $TEST_NAME - /login carried no Content-Security-Policy header"
    exit 1
fi

case "$policy" in
    *cdn.jsdelivr.net*)
        echo "FAIL: $TEST_NAME - the policy still whitelists cdn.jsdelivr.net: $policy"
        exit 1
        ;;
esac

case "$policy" in
    *fonts.g*)
        echo "FAIL: $TEST_NAME - the policy still whitelists a font host: $policy"
        exit 1
        ;;
esac
echo "[TEST] 3. OK - the policy is same-origin for every mirrored asset" >&2

# ---------------------------------------------------------------------------
# Step 4: an emitted css link is actually served, as text/css.
# ---------------------------------------------------------------------------
echo "[TEST] 4. An emitted /_vendor/ stylesheet is served..." >&2
css_name="$(printf '%s\n' "$vendor_refs" | grep -E '\.css$' | head -1 || true)"

if [ -z "$css_name" ]; then
    echo "FAIL: $TEST_NAME - the page emitted no /_vendor/ stylesheet to check"
    exit 1
fi

css_headers="$(curl -sI "${BASE}/_vendor/${css_name}" 2>/dev/null)"
css_status="$(printf '%s' "$css_headers" | head -1 | awk '{print $2}')"

if [ "$css_status" != "200" ]; then
    echo "FAIL: $TEST_NAME - /_vendor/${css_name} answered $css_status (expected 200)"
    exit 1
fi

if ! printf '%s' "$css_headers" | grep -qi '^content-type: *text/css'; then
    echo "FAIL: $TEST_NAME - /_vendor/${css_name} was not served as text/css"
    printf '%s' "$css_headers" | grep -i '^content-type'
    exit 1
fi
echo "[TEST] 4. OK - ${css_name} served as text/css" >&2

# ---------------------------------------------------------------------------
# Step 5: the font a localized stylesheet names is served, as font/woff2.
#
# This is the reference that has no declaration of its own: nothing in our code names the
# font URL, so before the store localized it the browser reached jsdelivr for it directly.
# ---------------------------------------------------------------------------
echo "[TEST] 5. The mirrored bootstrap-icons woff2 is served..." >&2
font_name="$(basename "$(ls "${STORE}"/[0-9a-f]*_bootstrap-icons.woff2 2>/dev/null | head -1)" 2>/dev/null || true)"

if [ -z "$font_name" ]; then
    echo "FAIL: $TEST_NAME - the store holds no bootstrap-icons woff2"
    exit 1
fi

font_headers="$(curl -sI "${BASE}/_vendor/${font_name}" 2>/dev/null)"
font_status="$(printf '%s' "$font_headers" | head -1 | awk '{print $2}')"

if [ "$font_status" != "200" ]; then
    echo "FAIL: $TEST_NAME - /_vendor/${font_name} answered $font_status (expected 200)"
    exit 1
fi

if ! printf '%s' "$font_headers" | grep -qi '^content-type: *font/woff2'; then
    echo "FAIL: $TEST_NAME - /_vendor/${font_name} was not served as font/woff2"
    printf '%s' "$font_headers" | grep -i '^content-type'
    exit 1
fi
echo "[TEST] 5. OK - ${font_name} served as font/woff2" >&2

echo "PASS: $TEST_NAME"
exit 0
