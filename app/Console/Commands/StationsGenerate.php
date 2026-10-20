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
                            {--force : Overwrite existing file without confirmation}';

    protected $description = 'Generate stations.json directly from the filesystem';

    public function handle(DataProcessor $processor): int
    {
        $baseDir = config('bramon.base_dir');
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

        $this->info('Stations fetched: ' . count($stations));

        // Build stations.json content
        $stationsJson = [];

        foreach ($stations as $station) {
            $op = $station['operador'] ?? '';
            $opId = is_array($op) ? ($op['id'] ?? '') : $op;

            $stationsJson[] = [
                'id' => $this->cleanString($station['id'] ?? ''),
                'name' => $this->cleanString($station['name'] ?? ''),
                'operador' => $this->cleanString($opId),
                'city' => $this->cleanString($station['city'] ?? 'Unknown'),
                'state' => $this->cleanString($station['state'] ?? 'Unknown'),
                'country' => $this->cleanString($station['country'] ?? 'BR'),
                'latitude' => $this->cleanFloat($station['latitude'] ?? 0.0),
                'longitude' => $this->cleanFloat($station['longitude'] ?? 0.0),
                'azimuth' => $this->cleanFloat($station['azimuth'] ?? 0.0),
                'elevation' => $this->cleanFloat($station['elevation'] ?? 0.0),
                'fov' => $this->cleanFloat($station['fov'] ?? 0.0),
                'camera_model' => $this->cleanString($station['camera_model'] ?? 'Unknown'),
                'camera_lens' => $this->cleanString($station['camera_lens'] ?? 'Unknown'),
                'camera_capture' => $this->cleanString($station['camera_capture'] ?? 'Unknown'),
                'active' => $station['active'] ?? true,
                'visible' => $station['visible'] ?? true,
            ];
        }

        $json = json_encode($stationsJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            $this->error('Failed to encode stations as JSON: ' . json_last_error_msg());

            foreach ($stationsJson as $i => $row) {
                if (json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) === false) {
                    $this->error("Error encoding station at index {$i} (" . ($row['name'] ?? '?') . '): ' . json_last_error_msg());
                    break;
                }
            }

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

    /**
     * Normaliza string para JSON: converte Latin-1/Windows-1252 para UTF-8
     * e remove controles. Cobre todos os campos de texto da estação.
     */
    protected function cleanString(mixed $value): string
    {
        $value = (string) ($value ?? '');

        if ($value !== '' && !mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return $clean ?? '';
    }

    /**
     * Normaliza número para JSON (evita INF/NAN que também quebram encode).
     */
    protected function cleanFloat(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }

        $float = (float) $value;

        return is_finite($float) ? $float : 0.0;
    }
}
