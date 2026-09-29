<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\FileSystemReader;
use App\Services\DataProcessor;
use App\Services\OperatorReader;
use App\Services\StationReader;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('bramon:scan', function () {
    $fileReader = new FileSystemReader(
        config('bramon.base_dir'),
        config('bramon.cache_ttl')
    );
    
    $files = $fileReader->getFiles(false);
    
    $this->info("Found " . count($files) . " files");
    
    // Group by station
    $byStation = [];
    foreach ($files as $file) {
        $byStation[$file['station']] = ($byStation[$file['station']] ?? 0) + 1;
    }
    
    foreach ($byStation as $station => $count) {
        $this->line("  {$station}: {$count} files");
    }
})->purpose('Scan filesystem for capture files');

Artisan::command('bramon:stats', function () {
    $fileReader = new FileSystemReader(
        config('bramon.base_dir'),
        config('bramon.cache_ttl')
    );
    $operatorReader = new OperatorReader(config('bramon.operators_file'));
    $stationReader = new StationReader(config('bramon.stations_file'));
    $dataProcessor = new DataProcessor($fileReader, $operatorReader, $stationReader);
    
    $files = $fileReader->getFiles(false);
    $captures = $dataProcessor->processCaptures($files);
    $stations = $dataProcessor->processStations($captures);
    $operators = $dataProcessor->processOperators($stations);
    $stats = $dataProcessor->calculateStats($captures, $stations, $operators, $files);
    
    $this->info("=== BRAMON Statistics ===");
    $this->table(
        ['Metric', 'Value'],
        [
            ['Total Captures', $stats['total_captures']],
            ['Total Stations', $stats['total_stations']],
            ['Total Operators', $stats['total_operators']],
            ['Total Files', $stats['total_files']],
            ['Earliest Capture', $stats['date_range']['earliest'] ?? 'N/A'],
            ['Latest Capture', $stats['date_range']['latest'] ?? 'N/A'],
        ]
    );
    
    $this->info("\n=== Captures by Station ===");
    $this->table(
        ['Station', 'Captures'],
        array_map(fn($s, $c) => [$s, $c], array_keys($stats['captures_by_station']), array_values($stats['captures_by_station']))
    );
    
    $this->info("\n=== Captures by Month ===");
    $this->table(
        ['Month', 'Captures'],
        array_map(fn($m, $c) => [$m, $c], array_keys($stats['captures_by_month']), array_values($stats['captures_by_month']))
    );
})->purpose('Show BRAMON statistics');

Artisan::command('bramon:cache-clear', function () {
    $fileReader = app(FileSystemReader::class);
    $fileReader->invalidateCache();
    
    $operatorReader = app(OperatorReader::class);
    $operatorReader->invalidateCache();
    
    $stationReader = app(StationReader::class);
    $stationReader->invalidateCache();
    
    $this->info('Cache cleared successfully');
})->purpose('Clear BRAMON cache');