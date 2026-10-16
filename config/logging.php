<?php

/*
| LOGS ARE PERSISTENT AND ARE PINNED HERE.
|
| Laravel's storage path is the tmp tree - derived state, wiped by rsx:clean - so a
| channel spelled storage_path('logs/...') would put the application's log history in
| a disposable directory. Every channel below names the path owner's logs_dir()
| instead, which is <storage>/logs.
|
| LOG TIMESTAMPS ARE IN THE HOST'S OWN TIMEZONE. The process runs in UTC, but every channel
| taps System_Timezone_Log_Tap, which stamps records in the operating system's zone (TZ,
| /etc/localtime, /etc/timezone; UTC when none is set) so the log agrees with `date` and
| with the host's other logs.
*/

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [
        'stack' => [
            'tap' => [App\RSpade\Core\Logging\System_Timezone_Log_Tap::class],
            'driver' => 'stack',
            'channels' => ['single'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'tap' => [App\RSpade\Core\Logging\System_Timezone_Log_Tap::class],
            'driver' => 'single',
            'path' => App\RSpade\Core\Paths\Rsx_Project_Paths::logs_dir() . '/laravel.log',
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'daily' => [
            'tap' => [App\RSpade\Core\Logging\System_Timezone_Log_Tap::class],
            'driver' => 'daily',
            'path' => App\RSpade\Core\Paths\Rsx_Project_Paths::logs_dir() . '/laravel.log',
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
        ],

        'monthly' => [
            'tap' => [App\RSpade\Core\Logging\System_Timezone_Log_Tap::class],
            'driver' => 'monthly',
            'path' => App\RSpade\Core\Paths\Rsx_Project_Paths::logs_dir() . '/laravel.log',
            'level' => env('LOG_LEVEL', 'debug'),
            'max_files' => 3,
            'replace_placeholders' => true,
        ],

        'slack' => [
            'tap' => [App\RSpade\Core\Logging\System_Timezone_Log_Tap::class],
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
            'emoji' => ':boom:',
            'level' => env('LOG_LEVEL', 'critical'),
        ],

        'papertrail' => [
            'tap' => [App\RSpade\Core\Logging\System_Timezone_Log_Tap::class],
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
        ],

        'stderr' => [
            'tap' => [App\RSpade\Core\Logging\System_Timezone_Log_Tap::class],
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
        ],

        'syslog' => [
            'tap' => [App\RSpade\Core\Logging\System_Timezone_Log_Tap::class],
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'errorlog' => [
            'tap' => [App\RSpade\Core\Logging\System_Timezone_Log_Tap::class],
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'tap' => [App\RSpade\Core\Logging\System_Timezone_Log_Tap::class],
            'path' => App\RSpade\Core\Paths\Rsx_Project_Paths::logs_dir() . '/laravel.log',
        ],
    ],

];
