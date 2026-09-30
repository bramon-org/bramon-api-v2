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

    public function handle(DataProcessor $processor): int
    {
        $indexDir = config('bramon.index_dir', base_path('storage/index'));
        $stationsFile = config('bramon.stations_file', base_path('storage/stations.json'));

        $this->info("Debug: indexDir={$indexDir}");
        $this->info("Debug: stationsFile={$stationsFile}");

        $index = new \App\Services\CaptureIndex($indexDir);

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

        $result = file_put_contents($stationsFile, $json);
        if ($result === false) {
            $this->error("Failed to write {$stationsFile}");
            return self::FAILURE;
        } else {
            $this->info("Wrote {$result} bytes to {$stationsFile}");
        }

        $this->info('Generated stations.json with ' . count($stationsJson) . ' stations');
        $this->info("Output file: {$stationsFile}");

        return self::SUCCESS;
    }
}
