<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | RSpade: the external API (/api/vN/...) and nothing else. It is called
    | with a bearer key, never a cookie, so any origin may call it and no
    | credentials are ever allowed (supports_credentials stays false). Every
    | other path - pages, /_ajax, uploads, file bytes - is same-origin only
    | and answers no Access-Control-* header at all.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
