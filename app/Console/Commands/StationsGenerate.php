<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CaptureIndex;
use App\Services\DataProcessor;

class StationsGenerate extends Command
{
    protected $signature = 'bramon:stations-generate 
                            {--output= : Output file path (default: stations.json in base dir)}
                            {--base-dir= : Base directory for captures (default: config value)}
                            {--force : Overwrite existing file without confirmation}';

    protected $description = 'Generate stations.json file from capture index';

    public function handle(CaptureIndex $index, DataProcessor $processor): int
    {
        $baseDir = $this->option('base-dir') ?: config('bramon.base_dir');
        $outputFile = $this->option('output') ?: $baseDir . '/stations.json';
        $force = $this->option('force');

        $this->info("Debug: baseDir={$baseDir}");
        $this->info("Debug: outputFile={$outputFile}");

        if (file_exists($outputFile) && !$force) {
            if (!$this->confirm("File {$outputFile} already exists. Overwrite?")) {
                $this->info('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        if (!$index->exists()) {
            $this->error('Capture index not found or not built. Run php artisan bramon:index first.');
            return self::FAILURE;
        }

        $stations = $processor->stationsFromIndex($index);
        $this->info('Debug: Stations fetched count=' . count($stations));

        // Generate minimal stations.json content
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

        $this->info('Debug: Prepared stationsJson count=' . count($stationsJson));

        $json = json_encode($stationsJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $this->error('Failed to encode stations as JSON');
            return self::FAILURE;
        }

        $result = file_put_contents($outputFile, $json);
        if ($result === false) {
            $this->error("Failed to write {$outputFile}");
            return self::FAILURE;
        } else {
            $this->info("Wrote {$result} bytes to {$outputFile}");
        }

        $this->info('Generated stations.json with ' . count($stationsJson) . ' stations');
        $this->info("Output file: {$outputFile}");

        return self::SUCCESS;
    }
}
