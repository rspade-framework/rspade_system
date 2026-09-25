<?php
/**
 * IDE Service Authentication
 *
 * SECURITY-CRITICAL: runs BEFORE any IDE service logic AND before Laravel boots
 * (invoked from public/index.php ahead of the autoloader), so it is plain PHP -
 * no framework classes, no config(). Every /_ide/service/* request passes here.
 *
 * Model: LOCAL-FILE GRANT. The framework (dev only) writes a single
 * ide-grant-<random>.token file into the bridge dir, mode-restricted and OUTSIDE
 * the web docroot. A legitimate co-located IDE reads that file from local disk and
 * presents its contents in the X-Ide-Token header; we verify it constant-time
 * (hash_equals). Possession proves local read access - which is the grant. The
 * secret NEVER crosses the wire except as this bearer over TLS. There is NO
 * network endpoint that mints or returns a token.
 *
 * ONE accepted path: X-Ide-Token matches the on-disk grant token. There is no loopback
 * exemption - the network position of a caller is not proof of local file access.
 *
 * HARD GATE FIRST: the bridge exists in DEVELOPMENT MODE AND NOWHERE ELSE. RSX_MODE is
 * the single mode switch and anything but 'development' is refused here, before any
 * token is read - there is no env key, no config value and no opt-in that reopens it.
 * That is the whole rule, and it is enforced twice by construction: the grant token the
 * bridge authenticates with is only ever written in development, and this gate refuses
 * even if one were somehow present.
 */

// Base path - framework is in system/ subdirectory
// @REALPATH-EXCEPTION - Bootstrap file: runs before helpers loaded, needs PHP's realpath()
$system_path = realpath(__DIR__ . '/../../../..');
// @REALPATH-EXCEPTION - Bootstrap file: runs before helpers loaded, needs PHP's realpath()
define('IDE_AUTH_BASE_PATH', realpath($system_path . '/..'));  // Project root
define('IDE_AUTH_SYSTEM_PATH', $system_path);                  // Framework root

// Helper to get framework paths
function ide_auth_framework_path($relative_path) {
    return IDE_AUTH_SYSTEM_PATH . '/' . ltrim($relative_path, '/');
}

// User-data storage root. This gate runs WITHOUT Laravel, so the answer comes from the
// pre-boot resolver - the same one Rsx_Project_Paths delegates to, so the bridge
// directory a booted process writes is the one this gate reads.
function ide_auth_storage_path($relative_path = '') {
    require_once IDE_AUTH_SYSTEM_PATH . '/bootstrap/rsx_paths.php';

    $base = rsx_paths_storage_root();
    return $relative_path ? $base . '/' . ltrim($relative_path, '/') : $base;
}

// JSON response helper
function ide_auth_json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Error response helper
function ide_auth_error_response($message, $code = 400) {
    ide_auth_json_response(['success' => false, 'error' => $message], $code);
}

// Strip surrounding whitespace/CR and one layer of matching quotes from a raw value.
function ide_auth_env_normalize($value) {
    $value = trim((string) $value);
    if (strlen($value) >= 2) {
        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
            $value = trim(substr($value, 1, -1));
        }
    }

    return $value;
}

// Resolve an environment key the way a pre-boot gate must: the PROCESS environment
// wins (a container or vhost can supply RSX_MODE without touching .env), then the
// .env file parsed line by line - optional "export ", optional single/double quotes,
// surrounding whitespace and CRLF endings all normalize away. Returns null when the
// key is set nowhere.
function ide_auth_env($key, $env_content) {
    $process_value = getenv($key);
    if ($process_value !== false) {
        return ide_auth_env_normalize($process_value);
    }

    foreach (explode("\n", $env_content) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = ltrim(substr($line, 7));
        }

        $separator = strpos($line, '=');
        if ($separator === false || trim(substr($line, 0, $separator)) !== $key) {
            continue;
        }

        return ide_auth_env_normalize(substr($line, $separator + 1));
    }

    return null;
}

// DEVELOPMENT ONLY (resolved pre-boot, no config()).
$env_file = IDE_AUTH_BASE_PATH . '/.env';
$env_content = file_exists($env_file) ? file_get_contents($env_file) : '';

// RSX_MODE is the single mode switch; APP_ENV is not read anywhere. Absent means
// development, which is what a fresh checkout with no .env is, and 'dev' is the alias
// Rsx::get_mode() normalizes - this gate answers the same question it does, so it has to
// accept the same spellings. Anything else, including an unreadable or invalid value, is
// refused: an answer this gate cannot understand is not an answer that opens the bridge.
$mode = strtolower((string) ide_auth_env('RSX_MODE', $env_content));

if ($mode !== '' && $mode !== 'development' && $mode !== 'dev') {
    ide_auth_error_response('IDE services are development-only', 403);
}

// Parse request URI to get service
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$service_path = str_replace('/_ide/service', '', $request_uri);
$service_path = trim($service_path, '/');

// THE TOKEN IS ALWAYS REQUIRED. There is no loopback or Host-based exemption: whether a
// request "came from localhost" is a statement about the network topology in front of
// this process (a proxy on the same box makes every request loopback), never proof that
// the caller can read local files - which is what the grant actually proves.
// Local-file grant: the caller must present the contents of the on-disk
// ide-grant-<random>.token file. Only a process with local read access to the
// (docroot-excluded, mode-restricted) bridge dir could have obtained it, so
// possession is the grant. Verified constant-time; fail closed.
$presented = trim((string) ($_SERVER['HTTP_X_IDE_TOKEN'] ?? ''));
if ($presented === '') {
    ide_auth_error_response('Authentication required', 401);
}

$bridge_dir = ide_auth_storage_path('rsx-ide-bridge');
$token_files = glob($bridge_dir . '/ide-grant-*.token') ?: [];
if (empty($token_files)) {
    ide_auth_error_response('IDE bridge grant not established', 401);
}

// ONLY THE TWO NEWEST GRANTS AUTHENTICATE (Ide_Bridge_Token::ACTIVE_GRANTS).
//
// Rotation retires everything older, so in a healthy tree this slice is the whole
// directory. It is applied here anyway because auth is where the consequence lands:
// if a rotation ever fails to delete - a permissions problem, a half-finished
// manual copy - retired secrets would otherwise keep opening the bridge for as long
// as the files sat there, which is exactly the property rotation exists to remove.
//
// Newest FIRST by the document's own issued_at (filemtime has one-second
// granularity and cannot separate two grants minted in the same second). The count
// is duplicated as a literal rather than read from Ide_Bridge_Token::ACTIVE_GRANTS
// because this file is included before the autoloader.
$issued_at = [];
foreach ($token_files as $token_file) {
    $document = json_decode((string) file_get_contents($token_file), true);
    $issued_at[$token_file] = is_array($document) && isset($document['issued_at']) && is_numeric($document['issued_at'])
        ? (float) $document['issued_at']
        : 0.0;
}
usort($token_files, static function ($a, $b) use ($issued_at) {
    $order = $issued_at[$b] <=> $issued_at[$a];
    return $order !== 0 ? $order : strcmp($b, $a);
});
$token_files = array_slice($token_files, 0, 2);

// The grant file is a JSON document {"secret": ..., "app_url": ...}; only the
// secret authenticates. A file that does not parse, or carries no secret, is not
// a grant - it is skipped, never treated as a match.
$grant_ok = false;
foreach ($token_files as $token_file) {
    $decoded = json_decode((string) file_get_contents($token_file), true);
    if (!is_array($decoded) || !isset($decoded['secret']) || !is_string($decoded['secret'])) {
        continue;
    }
    $secret = trim($decoded['secret']);
    if ($secret !== '' && hash_equals($secret, $presented)) {
        $grant_ok = true;
        break;
    }
}
if (!$grant_ok) {
    ide_auth_error_response('Invalid IDE token', 401);
}


// Authentication passed
define('IDE_AUTH_PASSED', true);

// Suppress console_debug output for IDE service requests
// These are programmatic API calls, not user-facing pages
define('SUPPRESS_CONSOLE_DEBUG_OUTPUT', true);
