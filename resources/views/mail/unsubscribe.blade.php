{{--
    RSpade unsubscribe screen - the page behind every non-transactional footer link
    (App\RSpade\Core\Mail\Mail_Unsubscribe_Controller).

    STANDALONE ON PURPOSE, exactly like errors/rsx_error.blade.php: inline styles, no
    bundle, no manifest view lookup, no JavaScript. The visitor is a mail recipient who
    may not be a user of this application at all, and honouring an opt-out must never
    depend on an application bundle loading.

    Palette tracks rsx/theme/variables.scss (primary #0d6efd, gray-900 #212529,
    gray-600 #6c757d, gray-300 #dee2e6, gray-100 #f8f9fa).

    Variables: $state ('confirm'|'done'), $email, $category_label, $action_url,
    $product_name, $scope ('category'|'all'|null)
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $state === 'done' ? 'Unsubscribed' : 'Unsubscribe' }}</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            background: #f8f9fa;
            color: #212529;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 16px;
            line-height: 1.5;
        }

        .rsx-unsubscribe {
            width: 100%;
            max-width: 560px;
            padding: 40px;
            background: #ffffff;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
        }

        .rsx-unsubscribe__product {
            margin: 0 0 20px;
            color: #6c757d;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .rsx-unsubscribe__heading {
            margin: 0 0 12px;
            font-size: 26px;
            font-weight: 600;
            line-height: 1.2;
        }

        .rsx-unsubscribe__message {
            margin: 0 0 24px;
            color: #6c757d;
        }

        .rsx-unsubscribe__address {
            display: block;
            margin: 0 0 24px;
            padding: 10px 14px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            background: #f8f9fa;
            font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, Courier, monospace;
            font-size: 14px;
            word-break: break-all;
        }

        .rsx-unsubscribe__choice {
            display: block;
            margin: 0 0 12px;
        }

        .rsx-unsubscribe__choice input {
            margin-right: 8px;
        }

        .rsx-unsubscribe__button {
            margin-top: 20px;
            padding: 9px 20px;
            border: 0;
            border-radius: 4px;
            background: #0d6efd;
            color: #ffffff;
            font-size: 15px;
            cursor: pointer;
        }

        .rsx-unsubscribe__button:hover {
            background: #0b5ed7;
        }
    </style>
</head>
<body>
    <div class="rsx-unsubscribe">
        <p class="rsx-unsubscribe__product">{{ $product_name }}</p>

        @if ($state === 'done')
            <h1 class="rsx-unsubscribe__heading">Unsubscribed</h1>

            @if ($scope === 'all')
                <p class="rsx-unsubscribe__message">
                    This address will no longer receive any non-essential email from us.
                    Messages you specifically ask for - a password reset, for example - are still sent.
                </p>
            @else
                <p class="rsx-unsubscribe__message">
                    This address will no longer receive {{ strtolower($category_label) }} email from us.
                </p>
            @endif

            <span class="rsx-unsubscribe__address">{{ $email }}</span>
        @else
            <h1 class="rsx-unsubscribe__heading">Unsubscribe</h1>
            <p class="rsx-unsubscribe__message">
                Confirm that this address should stop receiving {{ strtolower($category_label) }} email from us.
            </p>

            <span class="rsx-unsubscribe__address">{{ $email }}</span>

            <form method="post" action="{{ $action_url }}">
                <label class="rsx-unsubscribe__choice">
                    <input type="radio" name="scope" value="category" checked>
                    Stop {{ strtolower($category_label) }} email
                </label>
                <label class="rsx-unsubscribe__choice">
                    <input type="radio" name="scope" value="all">
                    Stop all non-essential email
                </label>

                <button class="rsx-unsubscribe__button" type="submit">Confirm</button>
            </form>
        @endif
    </div>
</body>
</html>
