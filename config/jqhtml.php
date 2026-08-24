<?php

return [
    // Where to store source maps
    'source_maps_path' => storage_path('jqhtml-sourcemaps'),

    // Show source code context in errors
    // Derived from RSX_MODE (the single mode switch); APP_DEBUG is not read
    // anywhere. Config files cannot call config(), hence the repeated expression.
    'show_source_context' => env('RSX_MODE', 'development') !== 'production',

    // Lines of context around errors
    'context_lines' => 5,

    // Enable source map generation
    'enable_source_maps' => env('RSX_MODE', 'development') !== 'production',

    // Source map mode: 'inline', 'external', or 'both'
    'source_map_mode' => 'external',
];