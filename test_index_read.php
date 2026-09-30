<?php

require __DIR__ . '/vendor/autoload.php';

use App\Services\CaptureIndex;
use App\Services\DataProcessor;
use Illuminate\Foundation\Application;

$app = require_once __DIR__ . '/bootstrap/app.php';

// Boot the application kernel to have DI and Config working
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$indexDir = config('bramon.index_dir', base_path('storage/index'));

echo "Testing CaptureIndex read with indexDir: {$indexDir}\n";

$index = new CaptureIndex($indexDir);

if (!$index->exists()) {
    echo "Index does not exist or is not built.\n";
    exit(1);
}

$dataProcessor = $app->make(DataProcessor::class);

$stations = $dataProcessor->stationsFromIndex($index);

$stationCount = count($stations);
echo "Stations found: {$stationCount}\n";

if ($stationCount > 0) {
    echo "Sample stations:\n";
    $sample = array_slice($stations, 0, min(5, $stationCount));
    foreach ($sample as $station) {
        echo "- Name: " . ($station['name'] ?? 'Unknown') . ", ID: " . ($station['id'] ?? 'N/A') . ", Operator ID: " . (($station['operador']['id'] ?? '') ?: 'None') . "\n";
    }
} else {
    echo "No stations were retrieved from the index.\n";
}

exit(0);
