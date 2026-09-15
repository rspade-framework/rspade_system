<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been set up for each driver as an example of the required values.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    | THE LOCAL DISK IS PINNED TO PERSISTENT STORAGE. Laravel's storage path is the
    | tmp tree, which rsx:clean wipes, so the local and public disks name the path
    | owner's app_dir() - <storage>/app - rather than storage_path('app'). A disk is
    | how an application stores a file it expects to still be there tomorrow.
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => App\RSpade\Core\Paths\Rsx_Project_Paths::app_dir(),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => App\RSpade\Core\Paths\Rsx_Project_Paths::app_dir('public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => App\RSpade\Core\Paths\Rsx_Project_Paths::app_dir('public'),
    ],

];
