<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CaptureIndex;
use App\Services\DataProcessor;

/**
 * Generates stations.json from the capture index.
 *
 * Reads station aggregates and metadata from the prebuilt CaptureIndex
 * (the same data source as bramon:index), so stations are derived from
 * actual captures rather than scanning the filesystem directly.
 */
class StationsGenerate extends Command
{
    protected $signature = 'bramon:stations-generate 
                            {--output= : Output file path (default: stations.json in base dir)}
                            {--base-dir= : Base directory for captures (default: config value)}
                            {--index-dir= : Index directory (default: config value)}
                            {--force : Overwrite existing file without confirmation}';

    protected $description = 'Generate stations.json from the capture index';

    public function handle(DataProcessor $processor): int
    {
        $baseDir = $this->option('base-dir') ?? config('bramon.base_dir');
        $indexDir = $this->option('index-dir') ?? config('bramon.index_dir');
        $outputFile = $this->option('output') ?? config('bramon.stations_file', $baseDir . '/stations.json');
        $force = $this->option('force');

        $this->info("Reading from index: {$indexDir}");

        $index = new CaptureIndex($indexDir);

        if (!$index->exists()) {
            $this->error('Capture index not found. Run php artisan bramon:index first.');
            return self::FAILURE;
        }

        if (file_exists($outputFile) && !$force) {
            if (!$this->confirm("File {$outputFile} already exists. Overwrite?")) {
                $this->info('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        $this->info('Reading stations from index...');

        // Get stations from the index (same as bramon:index does)
        $stations = $processor->stationsFromIndex($index);

        // Build stations.json content
        $stationsJson = [];

        foreach ($stations as $station) {
            $stationsJson[] = [
                'id' => $station['id'],
                'name' => $station['name'],
                'operador' => $station['operador']['id'] ?? '',
                'city' => $station['city'] ?? 'Unknown',
                'state' => $station['state'] ?? 'Unknown',
                'country' => $station['country'] ?? 'BR',
                'latitude' => $station['latitude'] ?? 0.0,
                'longitude' => $station['longitude'] ?? 0.0,
                'azimuth' => $station['azimuth'] ?? 0.0,
                'elevation' => $station['elevation'] ?? 0.0,
                'fov' => $station['fov'] ?? 0.0,
                'camera_model' => $station['camera_model'] ?? 'Unknown',
                'camera_lens' => $station['camera_lens'] ?? 'Unknown',
                'camera_capture' => $station['camera_capture'] ?? 'Unknown',
                'active' => $station['active'] ?? true,
                'visible' => $station['visible'] ?? true,
            ];
        }

        $json = json_encode($stationsJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $this->error('Failed to encode stations as JSON');
            return self::FAILURE;
        }

        $result = file_put_contents($outputFile, $json);
        if ($result === false) {
            $this->error("Failed to write {$outputFile}");
            return self::FAILURE;
        }

        $this->info('Generated stations.json with ' . count($stationsJson) . ' stations');
        $this->info("Output file: {$outputFile}");
        $this->info('Peak memory: ' . $this->humanBytes(memory_get_peak_usage(true)));

        return self::SUCCESS;
    }

    protected function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return number_format($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
