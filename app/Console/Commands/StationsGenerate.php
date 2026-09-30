<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DataProcessor;

/**
 * Generates stations.json directly from the filesystem.
 *
 * Reads station metadata and capture files directly from the filesystem,
 * without depending on the prebuilt CaptureIndex (bramon:index).
 */
class StationsGenerate extends Command
{
    protected $signature = 'bramon:stations-generate 
                            {--output= : Output file path (default: stations.json in base dir)}
                            {--base-dir= : Base directory for captures (default: config value)}
                            {--force : Overwrite existing file without confirmation}';

    protected $description = 'Generate stations.json directly from the filesystem';

    public function handle(DataProcessor $processor): int
    {
        $baseDir = $this->option('base-dir') ?? config('bramon.base_dir');
        $outputFile = $this->option('output') ?? config('bramon.stations_file', $baseDir . '/stations.json');
        $force = $this->option('force');

        $this->info("Reading from filesystem: {$baseDir}");

        if (!is_dir($baseDir)) {
            $this->error("Base directory does not exist: {$baseDir}");
            return self::FAILURE;
        }

        if (file_exists($outputFile) && !$force) {
            if (!$this->confirm("File {$outputFile} already exists. Overwrite?")) {
                $this->info('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        $this->info('Reading stations from filesystem...');

        // Get stations directly from the filesystem (no index dependency)
        $stations = $processor->stationsFromFileSystem();

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
