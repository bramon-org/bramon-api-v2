<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use function Laravel\Prompts\error;

class DataProcessor
{
    protected FileSystemReader $fileReader;
    protected ?OperatorReader $operatorReader;
    protected ?StationReader $stationReader;

    public function __construct(
        FileSystemReader $fileReader,
        ?OperatorReader $operatorReader = null,
        ?StationReader $stationReader = null
    ) {
        $this->fileReader = $fileReader;
        $this->operatorReader = $operatorReader;
        $this->stationReader = $stationReader;
    }

    /**
     * Generate a unique ID for a capture.
     */
    protected function generateId(string $station, string $date, string $time): string
    {
        $key = "{$station}_{$date}_{$time}";
        return substr(md5($key), 0, 32);
    }

    /**
     * Group files by capture (station, date, time).
     */
    protected function groupFilesByCapture(array $files): array
    {
        $groups = [];

        foreach ($files as $file) {
            $key = "{$file['station']}_{$file['date']}_{$file['time']}";
            $groups[$key][] = $file;
        }

        return $groups;
    }

    /**
     * Process files into capture objects.
     */
    public function processCaptures(array $files): array
    {
        $groups = $this->groupFilesByCapture($files);
        $captures = [];

        foreach ($groups as $key => $fileList) {
            [$station, $date, $time] = explode('_', $key, 3);
            $captureId = $this->generateId($station, $date, $time);

            // Parse datetime
            try {
                $dt = \DateTime::createFromFormat('YmdHis', "{$date}{$time}");
                if (!$dt) {
                    continue;
                }
                $capturedAt = $dt->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                continue;
            }

            // Process files
            $fileModels = [];
            $hasMetadata = false;
            $hasPreview = false;
            $hasThumbnail = false;

            foreach ($fileList as $f) {
                $fileModels[] = [
                    'url' => $f['rel_path'],
                    'type' => $f['type'],
                    'filename' => $f['filename'],
                    'extension' => $f['extension'],
                    'size' => $f['size'] ?? null,
                    'modified_at' => $f['modified_at'] ?? null,
                ];

                match ($f['type']) {
                    'metadata' => $hasMetadata = true,
                    'preview' => $hasPreview = true,
                    'thumbnail' => $hasThumbnail = true,
                    default => null,
                };
            }

            $captures[] = [
                'id' => $captureId,
                'station' => $station,
                'captured_at' => $capturedAt,
                'capture_date' => $date,
                'capture_time' => $time,
                'files' => $fileModels,
                'file_count' => count($fileModels),
                'has_metadata' => $hasMetadata,
                'has_preview' => $hasPreview,
                'has_thumbnail' => $hasThumbnail,
                'class' => null,
                'fs' => null,
                'fe' => null,
                'sec' => null,
                'av' => null,
                'mag' => null,
                'analyzed' => false,
            ];
        }

        // Sort by captured_at descending (most recent first)
        usort($captures, fn($a, $b) => strcmp($b['captured_at'], $a['captured_at']));

        return $captures;
    }

    /**
     * Process captures into station objects.
     */
    public function processStations(array $captures): array
    {
        if ($this->stationReader) {
            return $this->loadStationsFromFile($captures);
        }

        return $this->processStationsFromCaptures($captures);
    }

    /**
     * Process stations from captures (fallback when no station file).
     */
    protected function processStationsFromCaptures(array $captures): array
    {
        $stationData = [];

        foreach ($captures as $capture) {
            $station = $capture['station'];

            if (!isset($stationData[$station])) {
                $stationData[$station] = [
                    'captures' => [],
                    'first_capture' => null,
                    'last_capture' => null,
                ];
            }

            $stationData[$station]['captures'][] = $capture;

            if ($stationData[$station]['first_capture'] === null
                || $capture['captured_at'] < $stationData[$station]['first_capture']) {
                $stationData[$station]['first_capture'] = $capture['captured_at'];
            }

            if ($stationData[$station]['last_capture'] === null
                || $capture['captured_at'] > $stationData[$station]['last_capture']) {
                $stationData[$station]['last_capture'] = $capture['captured_at'];
            }
        }

        $stations = [];
        foreach ($stationData as $stationName => $data) {
            $stationId = substr(md5($stationName), 0, 32);

            $stations[] = [
                'id' => $stationId,
                'name' => $stationName,
                'operador' => null,
                'city' => 'Unknown',
                'state' => 'Unknown',
                'country' => 'BR',
                'latitude' => 0.0,
                'longitude' => 0.0,
                'azimuth' => 0.0,
                'elevation' => 0.0,
                'fov' => 0.0,
                'camera_model' => 'Unknown',
                'camera_lens' => 'Unknown',
                'camera_capture' => 'Unknown',
                'active' => true,
                'visible' => true,
                'capture_count' => count($data['captures']),
                'first_capture' => $data['first_capture'],
                'last_capture' => $data['last_capture'],
            ];
        }

        // Sort by name
        usort($stations, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $stations;
    }

    /**
     * Load stations from JSON file and merge with capture data.
     */
    protected function loadStationsFromFile(array $captures): array
    {
        $rawStations = $this->stationReader->getStationsIndexed();

        // Group captures by station
        $capturesByStation = [];
        foreach ($captures as $capture) {
            $capturesByStation[$capture['station']][] = $capture;
        }

        $stations = [];

        foreach ($rawStations as $stationName => $stationData) {
            if (!$stationName) {
                continue;
            }

            $stationCaptures = $capturesByStation[$stationName] ?? [];
            $captureCount = count($stationCaptures);
            $firstCapture = $stationCaptures
                ? min(array_column($stationCaptures, 'captured_at'))
                : null;
            $lastCapture = $stationCaptures
                ? max(array_column($stationCaptures, 'captured_at'))
                : null;

            $stations[] = [
                'id' => $stationData['id'] ?? substr(md5($stationName), 0, 32),
                'name' => $stationName,
                'operador' => $stationData['operador'] ?? null,
                'city' => $stationData['city'] ?? 'Unknown',
                'state' => $stationData['state'] ?? 'Unknown',
                'country' => $stationData['country'] ?? 'BR',
                'latitude' => (float)($stationData['latitude'] ?? 0),
                'longitude' => (float)($stationData['longitude'] ?? 0),
                'azimuth' => (float)($stationData['azimuth'] ?? 0),
                'elevation' => (float)($stationData['elevation'] ?? 0),
                'fov' => (float)($stationData['fov'] ?? 0),
                'camera_model' => $stationData['camera_model'] ?? 'Unknown',
                'camera_lens' => $stationData['camera_lens'] ?? 'Unknown',
                'camera_capture' => $stationData['camera_capture'] ?? 'Unknown',
                'active' => (bool)($stationData['active'] ?? true),
                'visible' => (bool)($stationData['visible'] ?? true),
                'capture_count' => $captureCount,
                'first_capture' => $firstCapture,
                'last_capture' => $lastCapture,
            ];
        }

        // Sort by name
        usort($stations, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $stations;
    }

    /**
     * Process operators from JSON file, linking to stations.
     */
    public function processOperators(array $stations): array
    {
        $operators = [];

        if ($this->operatorReader) {
            $rawOperators = $this->operatorReader->getOperators();
            $stationMap = array_column($stations, null, 'name');
            $stationNames = array_keys($stationMap);

            foreach ($rawOperators as $opData) {
                $linkedStations = $this->operatorReader->getLinkedStations($opData, $stationMap);

                $operators[] = [
                    'id' => $opData['id'] ?? substr(md5($opData['email'] ?? ''), 0, 32),
                    'name' => $opData['nome'] ?? $opData['name'] ?? 'Operador Desconhecido',
                    'email' => $opData['email'] ?? '',
                    'role' => $opData['role'] ?? 'operator',
                    'visible' => (bool)($opData['visivel'] ?? $opData['visible'] ?? true),
                    'active' => (bool)($opData['ativo'] ?? $opData['active'] ?? true),
                    'station_count' => count($linkedStations),
                    'stations' => array_values($linkedStations),
                ];
            }
        } else {
            // Fallback: generate operators from stations
            foreach ($stations as $station) {
                $operatorId = substr(md5("op_{$station['name']}"), 0, 32);

                $operators[] = [
                    'id' => $operatorId,
                    'name' => "Operador {$station['name']}",
                    'email' => strtolower($station['name']) . '@bramonmeteor.org',
                    'role' => 'operator',
                    'visible' => true,
                    'active' => true,
                    'station_count' => 1,
                    'stations' => [$station['name']],
                ];
            }
        }

        // Sort by name
        usort($operators, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $operators;
    }

    /**
     * Calculate general statistics.
     */
    public function calculateStats(
        array $captures,
        array $stations,
        array $operators,
        array $files
    ): array {
        $capturesByStation = [];
        foreach ($captures as $capture) {
            $capturesByStation[$capture['station']] = ($capturesByStation[$capture['station']] ?? 0) + 1;
        }

        $capturesByMonth = [];
        foreach ($captures as $capture) {
            $monthKey = substr($capture['capture_date'], 0, 6); // YYYYMM
            $capturesByMonth[$monthKey] = ($capturesByMonth[$monthKey] ?? 0) + 1;
        }

        $dates = array_column($captures, 'captured_at');

        return [
            'total_captures' => count($captures),
            'total_stations' => count($stations),
            'total_operators' => count($operators),
            'total_files' => count($files),
            'date_range' => [
                'earliest' => $dates ? min($dates) : null,
                'latest' => $dates ? max($dates) : null,
            ],
            'captures_by_station' => $capturesByStation,
            'captures_by_month' => $capturesByMonth,
            'last_updated' => date('c'),
        ];
    }
}