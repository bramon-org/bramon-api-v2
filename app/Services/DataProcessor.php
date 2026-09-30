<?php

namespace App\Services;

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
     * Streams the input so file arrays are released as we go.
     *
     * @param  iterable<\App\Services\FileSystemReader|\Traversable<int,array<string,mixed>>|array<int,array<string,mixed>>>  $files
     * @return array<string,array<int,array<string,mixed>>>
     */
    protected function groupFilesByCapture(iterable $files): array
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
     *
     * @param  iterable<array<string,mixed>>  $captures
     * @return array<int,array<string,mixed>>
     */
    public function processStations(iterable $captures): array
    {
        if ($this->stationReader) {
            return $this->loadStationsFromFile($captures);
        }

        return $this->processStationsFromCaptures($captures);
    }

    /**
     * Process stations from captures (fallback when no station file).
     *
     * Only aggregates count/first/last per station, so the full capture list
     * is never retained in memory.
     *
     * @param  iterable<array<string,mixed>>  $captures
     * @return array<int,array<string,mixed>>
     */
    protected function processStationsFromCaptures(iterable $captures): array
    {
        $stationData = [];

        foreach ($captures as $capture) {
            $station = $capture['station'];
            $capturedAt = $capture['captured_at'];

            if (!isset($stationData[$station])) {
                $stationData[$station] = [
                    'capture_count' => 1,
                    'first_capture' => $capturedAt,
                    'last_capture' => $capturedAt,
                ];
                continue;
            }

            $stationData[$station]['capture_count']++;

            if ($capturedAt < $stationData[$station]['first_capture']) {
                $stationData[$station]['first_capture'] = $capturedAt;
            }

            if ($capturedAt > $stationData[$station]['last_capture']) {
                $stationData[$station]['last_capture'] = $capturedAt;
            }
        }

        $stations = [];
        foreach ($stationData as $stationName => $data) {
            $stations[] = [
                'id' => substr(md5($stationName), 0, 32),
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
                'capture_count' => $data['capture_count'],
                'first_capture' => $data['first_capture'],
                'last_capture' => $data['last_capture'],
            ];
        }

        unset($stationData);

        // Sort by name
        usort($stations, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $stations;
    }

    /**
     * Load stations from JSON file and merge with capture data.
     *
     * @param  iterable<array<string,mixed>>  $captures
     * @return array<int,array<string,mixed>>
     */
    protected function loadStationsFromFile(iterable $captures): array
    {
        $rawStations = $this->stationReader->getStationsIndexed();

        // Aggregate only count/first/last per station instead of keeping whole captures.
        $capturesByStation = [];
        foreach ($captures as $capture) {
            $station = $capture['station'];
            $capturedAt = $capture['captured_at'];

            if (!isset($capturesByStation[$station])) {
                $capturesByStation[$station] = [
                    'count' => 1,
                    'first' => $capturedAt,
                    'last' => $capturedAt,
                ];
                continue;
            }

            $capturesByStation[$station]['count']++;

            if ($capturedAt < $capturesByStation[$station]['first']) {
                $capturesByStation[$station]['first'] = $capturedAt;
            }

            if ($capturedAt > $capturesByStation[$station]['last']) {
                $capturesByStation[$station]['last'] = $capturedAt;
            }
        }

        $stations = [];

        foreach ($rawStations as $stationName => $stationData) {
            if (!$stationName) {
                continue;
            }

            $aggregate = $capturesByStation[$stationName] ?? null;
            $captureCount = $aggregate['count'] ?? 0;
            $firstCapture = $aggregate['first'] ?? null;
            $lastCapture = $aggregate['last'] ?? null;

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
     * Build station objects straight from the on-disk index aggregates.
     *
     * This is the path used by the listing endpoints: it merges the
     * curated stations.json metadata with pre-computed capture counts, so
     * request memory depends on the number of stations (hundreds) instead of
     * the number of files on disk (millions).
     *
     * @return array<int,array<string,mixed>>
     */
    public function stationsFromIndex(CaptureIndex $index): array
    {
        $aggregates = $index->stationAggregates();

        // Curated metadata is keyed by station name and stays small.
        $metadata = $this->stationReader?->getStationsIndexed() ?? [];

        $stations = [];

        // Stations present in the captures tree, enriched with metadata.
        foreach ($aggregates as $stationName => $aggregate) {
            $stations[] = $this->buildStation($stationName, $metadata[$stationName] ?? null, $aggregate);
        }

        // Stations that have metadata but no captures yet still belong in the
        // listing, with a zero capture count.
        foreach ($metadata as $stationName => $stationData) {
            if ($stationName === '' || isset($aggregates[$stationName])) {
                continue;
            }

            $stations[] = $this->buildStation($stationName, $stationData, null);
        }

        usort($stations, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $stations;
    }

    /**
     * Assemble a single station record.
     *
     * @param  array<string,mixed>|null  $stationData
     * @param  array<string,mixed>|null  $aggregate
     * @return array<string,mixed>
     */
    protected function buildStation(string $stationName, ?array $stationData, ?array $aggregate): array
    {
        return [
            'id' => $stationData['id'] ?? substr(md5($stationName), 0, 32),
            'name' => $stationName,
            'operador' => $stationData['operador'] ?? null,
            'city' => $stationData['city'] ?? 'Unknown',
            'state' => $stationData['state'] ?? 'Unknown',
            'country' => $stationData['country'] ?? 'BR',
            'latitude' => (float) ($stationData['latitude'] ?? 0),
            'longitude' => (float) ($stationData['longitude'] ?? 0),
            'azimuth' => (float) ($stationData['azimuth'] ?? 0),
            'elevation' => (float) ($stationData['elevation'] ?? 0),
            'fov' => (float) ($stationData['fov'] ?? 0),
            'camera_model' => $stationData['camera_model'] ?? 'Unknown',
            'camera_lens' => $stationData['camera_lens'] ?? 'Unknown',
            'camera_capture' => $stationData['camera_capture'] ?? 'Unknown',
            'active' => (bool) ($stationData['active'] ?? true),
            'visible' => (bool) ($stationData['visible'] ?? true),
            'capture_count' => (int) ($aggregate['count'] ?? 0),
            'first_capture' => $aggregate['first'] ?? null,
            'last_capture' => $aggregate['last'] ?? null,
        ];
    }

    /**
     * Process operators from JSON file, linking to stations.
     *
     * Falls back to one operator per station when operators.json is missing or
     * contains no usable entries, so a fresh install still lists operators
     * instead of returning an empty page.
     */
    public function processOperators(array $stations): array
    {
        $operators = [];

        $rawOperators = $this->operatorReader?->getOperators() ?? [];

        if (!empty($rawOperators)) {
            $stationMap = array_column($stations, null, 'name');

            foreach ($rawOperators as $opData) {
                $linkedStations = $this->operatorReader->getLinkedStations($opData, $stationMap);

                $operators[] = [
                    'id' => $opData['id'] ?? substr(md5($opData['email'] ?? ''), 0, 32),
                    'name' => $opData['nome'] ?? $opData['name'] ?? 'Operador Desconhecido',
                    'email' => $opData['email'] ?? '',
                    'role' => $opData['role'] ?? 'operator',
                    'visible' => (bool) ($opData['visivel'] ?? $opData['visible'] ?? true),
                    'active' => (bool) ($opData['ativo'] ?? $opData['active'] ?? true),
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
     * Aggregate statistics in a single streaming pass.
     *
     * Avoids materialising the full capture list just to count/group it.
     *
     * @param  iterable<array<string,mixed>>  $captures
     * @return array{
     *     total_captures:int,
     *     captures_by_station:array<string,int>,
     *     captures_by_month:array<string,int>,
     *     earliest:?string,
     *     latest:?string
     * }
     */
    public function aggregateCaptures(iterable $captures): array
    {
        $total = 0;
        $byStation = [];
        $byMonth = [];
        $earliest = null;
        $latest = null;

        foreach ($captures as $capture) {
            $total++;

            $station = $capture['station'];
            $byStation[$station] = ($byStation[$station] ?? 0) + 1;

            $monthKey = substr($capture['capture_date'], 0, 6); // YYYYMM
            $byMonth[$monthKey] = ($byMonth[$monthKey] ?? 0) + 1;

            $capturedAt = $capture['captured_at'];
            if ($earliest === null || $capturedAt < $earliest) {
                $earliest = $capturedAt;
            }
            if ($latest === null || $capturedAt > $latest) {
                $latest = $capturedAt;
            }
        }

        return [
            'total_captures' => $total,
            'captures_by_station' => $byStation,
            'captures_by_month' => $byMonth,
            'earliest' => $earliest,
            'latest' => $latest,
        ];
    }

    /**
     * Calculate general statistics.
     *
     * @param  iterable<array<string,mixed>>  $captures
     * @param  iterable<array<string,mixed>>  $stations
     * @param  iterable<array<string,mixed>>  $operators
     * @param  iterable<array<string,mixed>>  $files
     * @return array<string,mixed>
     */
    public function calculateStats(
        iterable $captures,
        iterable $stations,
        iterable $operators,
        iterable $files
    ): array {
        $stationsCount = is_array($stations) ? count($stations) : iterator_count($stations);
        $operatorsCount = is_array($operators) ? count($operators) : iterator_count($operators);
        $filesCount = is_array($files) ? count($files) : iterator_count($files);

        $aggregate = $this->aggregateCaptures($captures);

        return [
            'total_captures' => $aggregate['total_captures'],
            'total_stations' => $stationsCount,
            'total_operators' => $operatorsCount,
            'total_files' => $filesCount,
            'date_range' => [
                'earliest' => $aggregate['earliest'],
                'latest' => $aggregate['latest'],
            ],
            'captures_by_station' => $aggregate['captures_by_station'],
            'captures_by_month' => $aggregate['captures_by_month'],
            'last_updated' => date('c'),
        ];
    }
}