<?php

return [
    /*
    |--------------------------------------------------------------------------
    | BRAMON API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the BRAMON meteor observation network API.
    | This includes paths to data directories and JSON files.
    |
    */

    'base_dir' => env('BRAMON_BASE_DIR', '/mnt/dados/BRAMON'),

    'operators_file' => env('BRAMON_OPERATORS_FILE', '/mnt/dados/BRAMON/operators.json'),

    'stations_file' => env('BRAMON_STATIONS_FILE', '/mnt/dados/BRAMON/stations.json'),

    'cache_ttl' => env('BRAMON_CACHE_TTL', 60),

    'max_results' => env('BRAMON_MAX_RESULTS', 1000),

    'file_types' => [
        'metadata' => ['xml'],
        'preview' => ['jpg', 'jpeg', 'png'],
        'thumbnail' => ['bmp', 'thm'],
        'analyze' => ['xml'],
    ],

    'filename_pattern' => '/^M(?P<date>\d{8})_(?P<time>\d{6})_(?P<station>[^_]+)_(?P<type>[^.]+)\.(?P<ext>.+)$/i',
];