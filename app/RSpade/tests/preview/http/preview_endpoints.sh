#!/bin/bash
set -e

TEST_NAME="Document Preview Endpoints"
TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# HTTP integration test - runs against the live web server, no database switching.
# These assertions need neither a seeded attachment nor auth:
#   - the pdf.js module routes stream the pdfjs-dist bytes as text/javascript (Batch 4 installed
#     pdfjs-dist and vendors it via these routes);
#   - an unknown rendition key is rejected before any content is served.
# The seeded-PDF (200 application/pdf) and .bin (415) rendition rows are covered by the php
# in-process tests instead: the live web server uses the DEV database, and seeding a real
# attachment + minting a staff cookie over HTTP would require touching the dev DB (forbidden).

BASE="http://localhost"

echo "[SETUP] Preparing test..." >&2

if ! curl -s -o /dev/null --connect-timeout 2 "$BASE/_preview/pdfjs.mjs" 2>/dev/null; then
    echo "SKIP: $TEST_NAME - web server not reachable on localhost"
    exit 0
fi

echo "[TEST] Running preview endpoint assertions..." >&2

# ---------------------------------------------------------------------------
# Test 1: /_preview/pdfjs.mjs -> 200 text/javascript (pdfjs-dist vendored)
# NOTE: assert with GET (not HEAD) - the module bytes are what the browser's dynamic import()
# fetches. A GET returns 200 text/javascript; a HEAD is not exercised by the client.
# ---------------------------------------------------------------------------
echo "[TEST] 1. pdfjs.mjs is 200 text/javascript..." >&2
hdr_file=$(mktemp)
status=$(curl -s -o /dev/null -D "$hdr_file" -w '%{http_code}' "$BASE/_preview/pdfjs.mjs" 2>/dev/null)
ctype=$(grep -i '^Content-Type:' "$hdr_file" | tr -d '\r' | head -1)
rm -f "$hdr_file"
if [ "$status" != "200" ]; then
    echo "FAIL: $TEST_NAME - expected 200 for pdfjs.mjs, got: $status"
    exit 1
fi
if ! echo "$ctype" | grep -qi "text/javascript"; then
    echo "FAIL: $TEST_NAME - pdfjs.mjs served wrong Content-Type: $ctype"
    exit 1
fi
echo "[TEST] 1. OK - pdfjs.mjs returns 200 text/javascript" >&2

# ---------------------------------------------------------------------------
# Test 2: /_preview/pdf_worker.mjs -> 200 text/javascript (pdfjs-dist vendored)
# ---------------------------------------------------------------------------
echo "[TEST] 2. pdf_worker.mjs is 200 text/javascript..." >&2
hdr_file=$(mktemp)
status=$(curl -s -o /dev/null -D "$hdr_file" -w '%{http_code}' "$BASE/_preview/pdf_worker.mjs" 2>/dev/null)
ctype=$(grep -i '^Content-Type:' "$hdr_file" | tr -d '\r' | head -1)
rm -f "$hdr_file"
if [ "$status" != "200" ]; then
    echo "FAIL: $TEST_NAME - expected 200 for pdf_worker.mjs, got: $status"
    exit 1
fi
if ! echo "$ctype" | grep -qi "text/javascript"; then
    echo "FAIL: $TEST_NAME - pdf_worker.mjs served wrong Content-Type: $ctype"
    exit 1
fi
echo "[TEST] 2. OK - pdf_worker.mjs returns 200 text/javascript" >&2

# ---------------------------------------------------------------------------
# Test 3: unknown rendition key serves no PDF (rejected before any content).
# NOTE: an unknown key hits abort(404), which the dispatcher now converts to a real 404 -
# the SAME behavior as /_inline/:key and /_download/:key for an unknown key. The status
# itself is pinned by tests/dispatch/http/abort_status.sh; the security-relevant property
# asserted here is: NOT 200 and NOT application/pdf.
# ---------------------------------------------------------------------------
echo "[TEST] 3. Unknown rendition key serves no PDF..." >&2
hdr_file=$(mktemp)
status=$(curl -s -o /dev/null -D "$hdr_file" -w '%{http_code}' "$BASE/_preview/pdf/deadbeefnope_unknown_key" 2>/dev/null)
ctype=$(grep -i '^Content-Type:' "$hdr_file" | tr -d '\r' | head -1)
rm -f "$hdr_file"
if [ "$status" = "200" ]; then
    echo "FAIL: $TEST_NAME - unknown rendition key returned 200 (should never serve content)"
    exit 1
fi
if echo "$ctype" | grep -qi "application/pdf"; then
    echo "FAIL: $TEST_NAME - unknown rendition key served application/pdf: $ctype"
    exit 1
fi
echo "[TEST] 3. OK - unknown key served no PDF (status $status)" >&2

# ---------------------------------------------------------------------------
# Test 4: HEAD /_preview/pdfjs.mjs -> 200 text/javascript (headers preserved).
# Regression for the BinaryFileResponse HEAD 500: the dispatcher rewrites HEAD to GET
# for handlers, then stripped the body with setContent('') - which a BinaryFileResponse
# (Response::file) forbids, throwing a LogicException -> 500. A HEAD must now succeed
# 200 with the same headers as GET (the body is omitted by HTTP HEAD semantics).
# ---------------------------------------------------------------------------
echo "[TEST] 4. HEAD pdfjs.mjs is 200 text/javascript..." >&2
hdr_file=$(mktemp)
status=$(curl -s --head -o /dev/null -D "$hdr_file" -w '%{http_code}' "$BASE/_preview/pdfjs.mjs" 2>/dev/null)
ctype=$(grep -i '^Content-Type:' "$hdr_file" | tr -d '\r' | head -1)
rm -f "$hdr_file"
if [ "$status" != "200" ]; then
    echo "FAIL: $TEST_NAME - expected 200 for HEAD pdfjs.mjs, got: $status (regression: BinaryFileResponse HEAD 500)"
    exit 1
fi
if ! echo "$ctype" | grep -qi "text/javascript"; then
    echo "FAIL: $TEST_NAME - HEAD pdfjs.mjs served wrong Content-Type: $ctype"
    exit 1
fi
echo "[TEST] 4. OK - HEAD pdfjs.mjs returns 200 text/javascript" >&2

echo "PASS: $TEST_NAME"
exit 0
