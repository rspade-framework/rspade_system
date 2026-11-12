<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Geocoding Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for the geocoding system, which
    | handles geocoding of mailing addresses. An IP address's location is
    | Rsx_Geo_Ip (rsx:man geo_ip), which needs no configuration.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Mailing Address Geocoding
    |--------------------------------------------------------------------------
    |
    | Configuration for geocoding physical mailing addresses
    |
    */
    'mailing_address' => [
        // Which geocoding service to use
        'service' => env('GEOCODE_ADDRESS_SERVICE', 'google_maps'),
        
        // Available services and their configurations
        'services' => [
            'google_maps' => [
                'api_key' => env('GOOGLE_MAPS_API_KEY'),
                'region' => env('GOOGLE_MAPS_REGION', 'us'),
                'language' => env('GOOGLE_MAPS_LANGUAGE', 'en'),
            ],
            'mapbox' => [
                'api_key' => env('MAPBOX_API_KEY'),
            ],
            'here' => [
                'api_key' => env('HERE_API_KEY'),
            ],
            'opencage' => [
                'api_key' => env('OPENCAGE_API_KEY'),
            ],
            'locationiq' => [
                'api_key' => env('LOCATIONIQ_API_KEY'),
            ],
        ],
        
        // Maximum number of lookup attempts
        'max_attempts' => 3,
        
        // Retry delay in hours between lookup attempts
        'retry_delay' => 1,
        
        // Quota configuration
        'quota' => [
            // Maximum requests per day
            'daily_limit' => env('GEOCODE_ADDRESS_DAILY_LIMIT', 2500),
            
            // Lookback period for quota calculation in hours
            'lookback_period' => env('GEOCODE_ADDRESS_LOOKBACK_PERIOD', 26),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Geocoding Scheduling
    |--------------------------------------------------------------------------
    |
    | Configuration for the automatic geocoding process
    |
    */
    'scheduling' => [
        // Whether to enable automatic geocoding
        'enabled' => env('GEOCODING_SCHEDULING_ENABLED', true),
        
        // How often to check for new geocoding tasks (in minutes)
        'check_interval' => env('GEOCODING_CHECK_INTERVAL', 10),
        
        // How many addresses to process in a single run
        'batch_size' => [
            'mailing_address' => env('GEOCODING_BATCH_SIZE_ADDRESS', 1),
        ],
    ],
];