<?php

require __DIR__ . '/vendor/autoload.php';

use App\Services\CaptureIndex;
use Illuminate\Foundation\Application;

$app = require_once __DIR__ . '/bootstrap/app.php';

// Boot the application kernel to have DI and Config working
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$indexDir = config('bramon.index_dir', base_path('storage/index'));

echo "Testing CaptureIndex manifest with indexDir: {$indexDir}\n";

$index = new CaptureIndex($indexDir);

if (!$index->exists()) {
    echo "Index does not exist or is not built.\n";
    exit(1);
}

$manifest = $index->manifest();

if ($manifest === null) {
    echo "Manifest is null or not readable.\n";
    exit(1);
}

$stationAggregates = $index->stationAggregates();

echo "Manifest summary:\n";
echo "- Total captures: " . ($manifest['total_captures'] ?? 'N/A') . "\n";
echo "- Total stations: " . ($manifest['total_stations'] ?? 'N/A') . "\n";
echo "- Earliest: " . ($manifest['earliest'] ?? 'N/A') . "\n";
echo "- Latest: " . ($manifest['latest'] ?? 'N/A') . "\n";
echo "- Stations in aggregates: " . count($stationAggregates) . "\n";

if (count($stationAggregates) > 0) {
    echo "Sample station aggregates:\n";
    $sample = array_slice($stationAggregates, 0, 5);
    foreach ($sample as $stationName => $aggregate) {
        echo "- Station: {$stationName}, count: " . ($aggregate['count'] ?? 0) . ", first: " . ($aggregate['first'] ?? 'N/A') . ", last: " . ($aggregate['last'] ?? 'N/A') . "\n";
    }
} else {
    echo "No station aggregates found in manifest.\n";
}

exit(0);
