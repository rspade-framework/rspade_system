<?php
/**
 * Router for the url_fetch tests' local HTTP server (php -S 127.0.0.1:<port> fixture_router.php).
 *
 *   /bytes?n=N            N bytes of "x" with a Content-Length
 *   /stream?n=N           N bytes of "x" with NO Content-Length (the length is learned by reading)
 *   /lying?n=N&claim=M    claims Content-Length M, which the cap must catch before reading
 *   /named                a CSV with Content-Disposition: attachment; filename="report.csv"
 *   /png                  a 1x1 PNG at /files/pixel.png-style path name
 *   /status?code=C        answers status C
 *   /redirect?to=URL      302 to URL
 *   /loop                 302 to itself, for ever
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$n = (int) ($_GET['n'] ?? 0);

switch ($path) {
    case '/bytes':
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . $n);
        echo str_repeat('x', $n);
        break;

    case '/stream':
        header('Content-Type: application/octet-stream');
        // Chunks flushed one by one, no Content-Length: the size is only known by reading.
        $sent = 0;
        while ($sent < $n) {
            $chunk = min(65536, $n - $sent);
            echo str_repeat('x', $chunk);
            flush();
            $sent += $chunk;
        }
        break;

    case '/lying':
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . (int) ($_GET['claim'] ?? 0));
        echo str_repeat('x', $n);
        break;

    case '/named':
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="report.csv"');
        echo "a,b\n1,2\n";
        break;

    case '/pixel.png':
        header('Content-Type: image/png');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        break;

    case '/status':
        http_response_code((int) ($_GET['code'] ?? 500));
        echo 'status body';
        break;

    case '/redirect':
        header('Location: ' . $_GET['to'], true, 302);
        break;

    case '/loop':
        header('Location: /loop', true, 302);
        break;

    default:
        http_response_code(404);
        echo 'no such fixture';
}
