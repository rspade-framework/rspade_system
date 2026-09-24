<?php

/*
|--------------------------------------------------------------------------
| Mail Transport (Laravel's mail configuration)
|--------------------------------------------------------------------------
|
| HOW an email is handed to the outside world. This is Laravel's own file and
| its own .env keys (MAIL_MAILER, MAIL_SCHEME, MAIL_HOST, MAIL_PORT,
| MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS, MAIL_FROM_NAME, ...), so any
| mailer Laravel supports - or any mail-transport package that registers one
| with Mail::extend() - carries RSpade's queue.
|
| WHETHER an email is handed over at all is RSpade's, and lives in
| config('rsx.mail'): MAIL_DELIVERY (aiosmtpd | live | suppressed | disabled),
| the dev-site recipient gate, retries and the stale-mail guard. Only 'live'
| reads this file; 'aiosmtpd' captures into the development catcher whatever
| is configured here. See: php artisan rsx:man email
|
| An application adds or changes a mailer in rsx/resource/config/mail.php,
| which is deep-merged over this file.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | The mailer the queue sends through in 'live' mode. It must name an entry
    | in 'mailers' below. 'log' and 'array' deliver nothing, so 'live' refuses
    | them - use MAIL_DELIVERY=suppressed to record without sending.
    |
    */

    'default' => env('MAIL_MAILER', 'smtp'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Supported by Laravel: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    | "postmark", "resend", "log", "array", "failover", "roundrobin" - plus any
    | transport a package registers. The API transports need their own
    | composer package (rsx:man email, MAIL TRANSPORTS).
    |
    | SMTP TLS: 'scheme' => 'smtps' is TLS from the first byte (port 465 implies
    | it); otherwise STARTTLS is used whenever the server offers it. To REFUSE a
    | server that does not offer it, set 'require_tls' => true on the mailer.
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 1025),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            // No timeout: Symfony's socket defaults belong to the mail host.
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'sendmail',
            ],
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | Every queued email is sent from this address. An empty name uses the
    | application name (config('rsx.name')).
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
        'name' => env('MAIL_FROM_NAME', ''),
    ],

];
