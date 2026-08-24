<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * FIRST-RUN SETUP SCREEN
 *
 * An RSpade application needs to know its own URL: every generated link, the
 * realtime WebSocket endpoint and every mail link derive from APP_URL. A
 * container cannot work it out for itself - Docker never tells a container which
 * host port was mapped to it, and the hostname inside the container is a
 * meaningless id.
 *
 * But the FIRST REQUEST carries the answer. Whatever address a person reached
 * this application on is, by definition, the address it should generate links
 * for - scheme, host and port together, straight from the request.
 *
 * So rather than fail with "APP_URL is not set", the first request offers to set
 * it. This is what a new developer sees instead of a stack trace.
 *
 * WHEN THIS RUNS - all three required, so it is unreachable in normal operation:
 *   1. Development mode only. Outside development a blank APP_URL stays fatal;
 *      a production box must be configured deliberately, not by whoever browses
 *      it first.
 *   2. Only while APP_URL is blank. Setting it makes this permanently
 *      unreachable - there is no way back to this screen short of emptying the
 *      value by hand.
 *   3. Web requests only. The CLI has no browser to ask.
 *
 * WHEN IT DOES NOT ASK AT ALL. If the host the browser arrived on IS this machine's
 * own hostname, there is nothing to decide: the answer the screen would offer is the
 * only answer there is. That case is written straight to .env and the request
 * redirected, so a box reached under its own name never sees a setup screen.
 *
 * What gets written then is the LITERAL token - `APP_URL=https://$HOSTNAME`, not the
 * resolved name. RSpade already expresses "this machine" that way (Rsx_App_Url
 * resolves the token at every boot through gethostname()), so a container or VM that
 * is later renamed, rebuilt or cloned keeps working instead of generating links for a
 * name it no longer answers to. A non-default port is part of the address rather than
 * the machine, so it is appended after the token; resolution is a plain str_replace
 * and leaves it untouched.
 *
 * Runs pre-boot, with no autoloader, no config and no session, because a blank
 * APP_URL is precisely what prevents the framework from booting. That also means
 * the CSRF defence here is a self-contained double-submit token rather than the
 * framework's own.
 *
 * The two decisions this file makes - what address the request arrived on, and
 * whether that address is this machine itself - are pure functions at the top of the
 * file so they can be exercised directly.
 */

/**
 * The URL this request arrived on: scheme, host and port exactly as the browser used
 * them. Returns null when the Host header is absent or not a plausible host, in which
 * case there is nothing to offer and the normal boot reports its own error.
 *
 * @param array $server the request environment ($_SERVER)
 */
function rsx_first_run_detected_url(array $server): ?string
{
    $forwarded = strtolower(trim((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwarded === 'https' || $forwarded === 'http') {
        $scheme = $forwarded;
    } else {
        $https = strtolower(trim((string) ($server['HTTPS'] ?? '')));
        $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    }

    $host = trim((string) ($server['HTTP_HOST'] ?? ''));
    if ($host === '' || !preg_match('/^[A-Za-z0-9._\-]+(:[0-9]{1,5})?$/', $host)) {
        return null;
    }

    return $scheme . '://' . $host;
}

/**
 * The APP_URL value to write WITHOUT asking, or null when the screen should be shown.
 *
 * The one case that needs no question: the browsed host is this machine's own
 * hostname. Compared case-insensitively (hostnames are), and on the host alone - a
 * port belongs to the address, not to the identity of the machine, so it is carried
 * through onto the written value rather than being part of the comparison.
 *
 * @param string $detected_url scheme://host[:port] from rsx_first_run_detected_url()
 * @param string $os_hostname this machine's name (gethostname())
 */
function rsx_first_run_auto_app_url(string $detected_url, string $os_hostname): ?string
{
    $os_hostname = trim($os_hostname);
    if ($os_hostname === '') {
        return null;
    }

    $separator = strpos($detected_url, '://');
    if ($separator === false) {
        return null;
    }

    $scheme = substr($detected_url, 0, $separator);
    $authority = substr($detected_url, $separator + 3);

    $port = '';
    $colon = strrpos($authority, ':');
    if ($colon !== false) {
        $port = substr($authority, $colon);
        $authority = substr($authority, 0, $colon);
    }

    if (strcasecmp($authority, $os_hostname) !== 0) {
        return null;
    }

    // The literal token, never the resolved name - see the header docblock.
    return $scheme . '://$HOSTNAME' . $port;
}

/**
 * Set one key in an environment file, replacing every existing definition of it with a
 * single line. Returns false when the file cannot be read or written.
 */
function rsx_first_run_write_env_value(string $path, string $key, string $value): bool
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return false;
    }

    $out = [];
    $written = false;
    foreach ($lines as $line) {
        if (strncmp($line, $key . '=', strlen($key) + 1) === 0) {
            if (!$written) {
                $out[] = $key . '=' . $value;
                $written = true;
            }
            continue; // drop any duplicate definitions
        }
        $out[] = $line;
    }
    if (!$written) {
        $out[] = $key . '=' . $value;
    }

    return @file_put_contents($path, implode("\n", $out) . "\n") !== false;
}

(static function (): void {
    if (PHP_SAPI === 'cli') {
        return;
    }

    $system_dir = dirname(__DIR__);
    $root_env = $system_dir . '/../.env';

    // ------------------------------------------------------------------
    // Read the environment file directly. Dotenv has not run yet.
    // ------------------------------------------------------------------
    $read_env_value = static function (string $path, string $key): ?string {
        if (!is_file($path)) {
            return null;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }

        $found = null;
        foreach ($lines as $line) {
            if (strncmp($line, $key . '=', strlen($key) + 1) !== 0) {
                continue;
            }
            $value = trim(substr($line, strlen($key) + 1));
            // Last non-empty definition wins - a file can carry a blank
            // template line plus a real one appended later.
            if ($value !== '') {
                $found = $value;
            }
        }

        return $found;
    };

    // Creating .env is not this file's job: bootstrap/rsx_env_heal.php runs first
    // and is the ONE implementation of it (from the project-root .env.dist, and
    // never from the framework's own copy - a system/.env.dist that seeded a root
    // .env is precisely the shadowing bug that cost a field deployment). If .env
    // is absent here the heal already refused loudly and this is unreachable.
    if (!is_file($root_env)) {
        return;
    }

    $app_url = $read_env_value($root_env, 'APP_URL');

    // Already configured: the overwhelmingly common path, one file read.
    if ($app_url !== null && $app_url !== '') {
        return;
    }

    // Development only. An unset RSX_MODE defaults to development, matching
    // the framework's own default.
    $mode = strtolower((string) ($read_env_value($root_env, 'RSX_MODE') ?? 'development'));
    if ($mode !== 'development' && $mode !== 'dev') {
        return;
    }

    // ------------------------------------------------------------------
    // The URL this request arrived on - the whole point of this screen.
    // ------------------------------------------------------------------
    $detected_url = rsx_first_run_detected_url($_SERVER);
    if ($detected_url === null) {
        // No usable Host header - nothing to offer. Let the normal boot fail
        // with its own message rather than inventing a value.
        return;
    }

    // ------------------------------------------------------------------
    // NOTHING TO ASK: the browsed host IS this machine. Write it and go.
    //
    // No CSRF token here, and none is needed - unlike the POST branch this writes
    // nothing the visitor supplied. The value is a constant (the $HOSTNAME token),
    // and the scheme and port come from the request itself, which is the same
    // source the screen already trusts to compose the address it offers. A visitor
    // can influence this only by being able to reach the box under its own name,
    // which is the condition being recognised.
    // ------------------------------------------------------------------
    $auto_app_url = rsx_first_run_auto_app_url($detected_url, (string) gethostname());

    if ($auto_app_url !== null) {
        if (!rsx_first_run_write_env_value($root_env, 'APP_URL', $auto_app_url)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Could not write .env. Set APP_URL={$auto_app_url} by hand.\n";
            exit;
        }

        header('Location: ' . $detected_url . '/');
        http_response_code(302);
        exit;
    }

    // ------------------------------------------------------------------
    // Double-submit CSRF token. No session exists yet, so the form carries a
    // random value that must match a cookie set alongside it.
    // ------------------------------------------------------------------
    $cookie_name = 'rsx_first_run';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['rsx_set_app_url'])) {
        $submitted = (string) ($_POST['rsx_token'] ?? '');
        $expected = (string) ($_COOKIE[$cookie_name] ?? '');

        if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Setup token mismatch. Reload the page and try again.\n";
            exit;
        }

        if (!rsx_first_run_write_env_value($root_env, 'APP_URL', $detected_url)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Could not write .env. Set APP_URL={$detected_url} by hand.\n";
            exit;
        }

        // Clear the token and reload; the app boots normally from here on.
        setcookie($cookie_name, '', time() - 3600, '/');
        header('Location: ' . $detected_url . '/');
        http_response_code(302);
        exit;
    }

    $token = bin2hex(random_bytes(16));
    setcookie($cookie_name, $token, [
        'expires' => time() + 1800,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');

    // Self-contained: no external stylesheet, font or image. The asset pipeline
    // has not booted, and any request it made would land back on this screen.
    echo '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Welcome to RSpade</title>
<style>
  :root { color-scheme: light dark; }
  body {
    margin: 0; min-height: 100vh; display: flex; align-items: center;
    justify-content: center; background: #f4f5f7; color: #1c2024;
    font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
  }
  .card {
    background: #fff; max-width: 34rem; width: calc(100% - 2rem);
    border: 1px solid #d7dbe0; border-radius: 6px; padding: 2rem 2.25rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
  }
  h1 { margin: 0 0 .25rem; font-size: 1.35rem; font-weight: 600; }
  .sub { margin: 0 0 1.5rem; color: #5b6470; }
  .label { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em;
           color: #5b6470; margin-bottom: .35rem; }
  .url { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
         font-size: 1.05rem; background: #f0f2f5; border: 1px solid #dfe3e8;
         border-radius: 4px; padding: .7rem .85rem; word-break: break-all; }
  button { margin-top: 1.5rem; width: 100%; padding: .7rem 1rem; font-size: .95rem;
           font-weight: 600; color: #fff; background: #1f6feb; border: 1px solid #1a5fd0;
           border-radius: 4px; cursor: pointer; }
  button:hover { background: #1a5fd0; }
  .note { margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid #e6e9ed;
          font-size: .87rem; color: #5b6470; }
  code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
         background: #f0f2f5; padding: .1rem .3rem; border-radius: 3px; }
  @media (prefers-color-scheme: dark) {
    body { background: #16181c; color: #e6e9ed; }
    .card { background: #1c1f24; border-color: #2c313a; box-shadow: none; }
    .sub, .label, .note { color: #9aa4b2; }
    .url, code { background: #22262d; border-color: #2c313a; }
    .note { border-top-color: #2c313a; }
  }
</style>
</head>
<body>
  <div class="card">
    <h1>Welcome to RSpade</h1>
    <p class="sub">This application does not know its own address yet.</p>

    <div class="label">You are viewing it at</div>
    <div class="url">' . $e($detected_url) . '</div>

    <form method="post" action="/">
      <input type="hidden" name="rsx_token" value="' . $e($token) . '">
      <button type="submit" name="rsx_set_app_url" value="1">Use this address</button>
    </form>

    <p class="note">
      This sets <code>APP_URL</code> in your <code>.env</code> file. Every link the
      application generates is built from it, including the live-update connection.
      If you later move the application to a different host or port, update
      <code>APP_URL</code> to match - this screen only appears while the value is
      empty.
    </p>
  </div>
</body>
</html>';

    exit;
})();
