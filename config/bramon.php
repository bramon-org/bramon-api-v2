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

    /*
    |--------------------------------------------------------------------------
    | Capture index
    |--------------------------------------------------------------------------
    |
    | Prebuilt, on-disk index over the captures tree. Building it with
    | `php artisan bramon:index` lets the listing endpoints answer from a
    | small manifest instead of scanning every file on each request, which
    | is what previously exhausted the memory limit on shared hosting.
    |
    | Keep this outside the public web root.
    |
    */

    'index_dir' => env('BRAMON_INDEX_DIR', storage_path('app/bramon/index')),

    // When true, a missing index is rebuilt on first request instead of
    // returning 503. Leave false on shared hosting: a large rebuild can
    // exceed the FastCGI read timeout, so it is better to run the CLI.
    'index_auto_build' => env('BRAMON_INDEX_AUTO_BUILD', false),

    // Records buffered before the indexer spills a sorted chunk to disk.
    // This is the main memory knob for `php artisan bramon:index`: peak usage
    // is roughly 2-3x this value. Lower it if the CLI process runs out of
    // memory on a constrained host; raise it on a larger machine for speed.
    'index_chunk_size' => env('BRAMON_INDEX_CHUNK_SIZE', 5000),

    // Directory for temporary sort chunks during index building.
    // Defaults to the system temp dir. On shared hosting (e.g. DreamHost)
    // where sys_get_temp_dir() may be restricted or on a small partition,
    // set this to a writable path inside your home directory, e.g.:
    //   BRAMON_TEMP_DIR=/home/bramon_api/tmp
    'temp_dir' => env('BRAMON_TEMP_DIR', sys_get_temp_dir()),

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