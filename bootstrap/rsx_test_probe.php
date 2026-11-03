<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * PRE-BOOT LIVE-STACK TEST PROBE
 *
 * Answers what php-fpm was handed for this request - REMOTE_ADDR, the forwarded headers,
 * HTTPS, the URI - so a framework test (tests/proxority, Proxority_Live_Stack_Test) can
 * prove what the live nginx -> Proxority -> nginx -> php-fpm path delivers. It is the only
 * way such a test can see PHP's side of a request: nginx refuses every `.php` URL, so no
 * script can be dropped into system/public, and the test trees are never in a served
 * manifest, so no fixture route can answer either.
 *
 *     /_rsx_test_probe/<token>/<label>[?hold=1]
 *
 * CLOSED unless a test opened it. It answers only when ALL of these hold, and otherwise
 * returns without a byte, leaving the request to the application (which answers 404):
 *
 *   - the application is in DEVELOPMENT mode (the suite refuses every other mode);
 *   - <token> is 32 hex characters naming a marker file in rsx_paths_test_probe_dir()
 *     (tmp/test-probes), written by the test that owns it and deleted when it ends;
 *   - the marker's content is the owning test process's pid, and that process is alive -
 *     so a marker a killed test left behind opens nothing.
 *
 * The answer is the caller's own request echoed back; nothing else is read or disclosed.
 * `hold=1` makes it answer after a fixed two seconds, so a test can keep a worker busy long
 * enough to watch the proxy schedule around it. The duration is a property of the fixture
 * (a request that takes a while), not a bound on anything: the request always completes.
 *
 * Required by system/public/index.php after the pre-boot gates and before the autoloader.
 * Plain PHP, no framework: one string comparison on every request that is not a probe.
 */

require_once __DIR__ . '/rsx_paths.php';
require_once __DIR__ . '/rsx_mode.php';

(static function (): void {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

    if (!str_starts_with($path, '/_rsx_test_probe/')) {
        return;
    }

    if (rsx_preboot_mode() !== 'development') {
        return;
    }

    if (!preg_match('#^/_rsx_test_probe/([a-f0-9]{32})/([a-z0-9_]{1,40})$#', $path, $matches)) {
        return;
    }

    $owner = trim((string) @file_get_contents(rsx_paths_test_probe_dir() . '/' . $matches[1]));
    if ($owner === '' || !ctype_digit($owner) || !file_exists('/proc/' . $owner)) {
        return;
    }

    if (isset($_GET['hold'])) {
        sleep(2);
    }

    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode([
        'label' => $matches[2],
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
        'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        'x_real_ip' => $_SERVER['HTTP_X_REAL_IP'] ?? null,
        'x_forwarded_proto' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
        'x_rsx_scheme' => $_SERVER['HTTP_X_RSX_SCHEME'] ?? null,
        'https' => $_SERVER['HTTPS'] ?? null,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
        'uploaded_files' => count($_FILES),
    ]);

    exit;
})();
