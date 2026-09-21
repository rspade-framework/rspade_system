<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * PRE-BOOT ERROR PAGE
 *
 * The HTML shell the guards that run BEFORE Laravel exists use to say why the site
 * is not answering: the maintenance window in system/public/index.php and the
 * framework-version refusal in bootstrap/rsx_submodule_sync.php. Both run before
 * Composer's autoloader, so there is no class, no config, no view finder and no
 * Blade to reach for - a page here is a string this file builds.
 *
 * ITS TWIN IS system/resources/views/errors/rsx_error.blade.php, the framework's
 * booted error screen. The two carry the same tokens, the same BEM class names and
 * the same light/dark blocks, DUPLICATED ON PURPOSE and kept aligned by hand: the
 * blade cannot be rendered from here, and this file must not grow a dependency on
 * anything the boot has not done yet. Change one, change the other.
 *
 * No JavaScript and no external asset: the page renders on a box whose framework
 * may be the thing that is broken.
 *
 * REQUIRED WITH require_once. Both entrypoints reach it and either may be first.
 */

/**
 * Emit a complete pre-boot page. The caller exits; this function never does.
 *
 * $status  the HTTP status to answer with
 * $title   the heading, also the document title beside the status
 * $lines   one paragraph per entry, in order
 * $headers extra response headers as name => value (Retry-After, typically)
 */
function rsx_preboot_page_render(int $status, string $title, array $lines, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');

    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }

    $e = static function (string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    };

    $body = '';
    foreach ($lines as $line) {
        $body .= '            <p class="rsx-error__message">' . $e((string) $line) . "</p>\n";
    }

    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . $e((string) $status) . ' ' . $e($title) . '</title>
    <style>
        :root {
            color-scheme: light dark;

            --rsx-bg: #f4f5f7;
            --rsx-surface: #ffffff;
            --rsx-raised: #f0f2f5;
            --rsx-border: #d7dbe0;
            --rsx-text: #1c2024;
            --rsx-muted: #5b6470;
            --rsx-accent: #1f6feb;
            --rsx-accent-hover: #1a5fd0;
            --rsx-shadow: 0 1px 3px rgba(0, 0, 0, .06);

            --rsx-sans: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;
            --rsx-mono: ui-monospace, SFMono-Regular, \'JetBrains Mono\', Menlo, Consolas, monospace;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --rsx-bg: #0D1117;
                --rsx-surface: #161B26;
                --rsx-raised: #1C2333;
                --rsx-border: #2A3245;
                --rsx-text: #E6EDF3;
                --rsx-muted: #DCE0E7;
                --rsx-accent: #58A6FF;
                --rsx-accent-hover: #79B8FF;
                --rsx-shadow: none;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            background: var(--rsx-bg);
            color: var(--rsx-text);
            font-family: var(--rsx-sans);
            font-size: 16px;
            line-height: 1.5;
        }

        .rsx-error {
            width: 100%;
            max-width: 720px;
            background: var(--rsx-surface);
            border: 1px solid var(--rsx-border);
            border-radius: 6px;
            box-shadow: var(--rsx-shadow);
            overflow: hidden;
        }

        .rsx-error__body {
            padding: 40px;
            text-align: center;
        }

        .rsx-error__status {
            display: inline-block;
            padding: 2px 10px;
            margin-bottom: 20px;
            border: 1px solid var(--rsx-border);
            border-radius: 6px;
            background: var(--rsx-raised);
            color: var(--rsx-muted);
            font-family: var(--rsx-mono);
            font-size: 13px;
            letter-spacing: 0.08em;
        }

        .rsx-error__heading {
            margin: 0 0 12px;
            font-size: 28px;
            font-weight: 600;
            line-height: 1.2;
        }

        /* pre-wrap: a line may be an indented command the reader has to type. */
        .rsx-error__message {
            margin: 0 0 12px;
            color: var(--rsx-muted);
            white-space: pre-wrap;
        }

        .rsx-error__message:last-child {
            margin-bottom: 0;
        }
    </style>
</head>
<body>
    <div class="rsx-error">
        <div class="rsx-error__body">
            <div class="rsx-error__status">ERROR ' . $e((string) $status) . '</div>
            <h1 class="rsx-error__heading">' . $e($title) . '</h1>
' . $body . '        </div>
    </div>
</body>
</html>
';
}
