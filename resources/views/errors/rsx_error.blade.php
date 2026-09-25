{{--
    RSpade error screen - the framework's own page, rendered whenever the
    application declares no /error/ route for the status or its page fails
    (App\RSpade\Core\Errors\Error_Screens).

    STANDALONE ON PURPOSE: inline styles, no bundle, no manifest view lookup, no
    JavaScript. This page has to render when the application is broken.

    Its pre-boot twin is bootstrap/rsx_preboot_page.php, the hand-written shell the
    503s that run before Laravel exists (maintenance, submodule sync) emit. The two
    carry the same look by hand, because the pre-boot tier has no autoloader and no
    Blade to share this file with.

    Variables: $status, $heading, $message, $detail (array|null),
    $error_id (string|null), $home_url
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $status }} {{ $heading }}</title>
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

            --rsx-sans: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            --rsx-mono: ui-monospace, SFMono-Regular, 'JetBrains Mono', Menlo, Consolas, monospace;
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

        .rsx-error__message {
            margin: 0 0 32px;
            color: var(--rsx-muted);
        }

        .rsx-error__reference {
            margin: -20px 0 32px;
            color: var(--rsx-muted);
            font-family: var(--rsx-mono);
            font-size: 13px;
        }

        .rsx-error__action {
            display: inline-block;
            padding: 8px 18px;
            border: 1px solid var(--rsx-accent);
            border-radius: 6px;
            background: var(--rsx-accent);
            color: #ffffff;
            font-size: 15px;
            text-decoration: none;
        }

        .rsx-error__action:hover {
            border-color: var(--rsx-accent-hover);
            background: var(--rsx-accent-hover);
        }

        .rsx-error__detail {
            text-align: left;
            border-top: 1px solid var(--rsx-border);
            padding: 24px 40px 32px;
            background: var(--rsx-raised);
        }

        .rsx-error__detail-label {
            margin: 0 0 10px;
            color: var(--rsx-muted);
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .rsx-error__detail-message {
            margin: 0 0 6px;
            font-family: var(--rsx-mono);
            font-size: 14px;
            word-break: break-word;
        }

        .rsx-error__detail-origin {
            margin: 0 0 18px;
            color: var(--rsx-muted);
            font-family: var(--rsx-mono);
            font-size: 13px;
            word-break: break-word;
        }

        .rsx-error__trace {
            margin: 0;
            padding: 12px;
            max-height: 320px;
            overflow: auto;
            border: 1px solid var(--rsx-border);
            border-radius: 6px;
            background: var(--rsx-surface);
            color: var(--rsx-muted);
            font-family: var(--rsx-mono);
            font-size: 12.5px;
            line-height: 1.7;
            white-space: pre;
        }
    </style>
</head>
<body>
    <div class="rsx-error">
        <div class="rsx-error__body">
            <div class="rsx-error__status">ERROR {{ $status }}</div>
            <h1 class="rsx-error__heading">{{ $heading }}</h1>
            <p class="rsx-error__message">{{ $message }}</p>
            @if (!empty($error_id))
                <p class="rsx-error__reference">Reference: {{ $error_id }}</p>
            @endif
            <a class="rsx-error__action" href="{{ $home_url }}">Return to Home</a>
        </div>

        @if ($detail)
            <div class="rsx-error__detail">
                <p class="rsx-error__detail-label">{{ $detail['class'] }}</p>
                <p class="rsx-error__detail-message">{{ $detail['message'] }}</p>
                <p class="rsx-error__detail-origin">{{ $detail['file'] }}:{{ $detail['line'] }}</p>
                @if (!empty($detail['frames']))
                    <pre class="rsx-error__trace">{{ implode("\n", $detail['frames']) }}</pre>
                @endif
            </div>
        @endif
    </div>
</body>
</html>
