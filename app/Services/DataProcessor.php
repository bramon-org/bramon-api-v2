<?php

namespace App\Services;

class DataProcessor
{
    protected FileSystemReader $fileReader;
    protected ?OperatorReader $operatorReader;
    protected ?StationReader $stationReader;
    protected ?CaptureIndex $index;

    public function __construct(
        FileSystemReader $fileReader,
        ?OperatorReader $operatorReader = null,
        ?StationReader $stationReader = null,
        ?CaptureIndex $index = null
    ) {
        $this->fileReader = $fileReader;
        $this->operatorReader = $operatorReader;
        $this->stationReader = $stationReader;
        $this->index = $index;
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
        $stations = $this->stationReader
            ? $this->loadStationsFromFile($captures)
            : $this->processStationsFromCaptures($captures);

        $this->attachOperators($stations);

        return $stations;
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
                'altitude' => null,
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
                // Resolved by attachOperators() from the station name.
                'operador' => null,
                'city' => $stationData['city'] ?? 'Unknown',
                'state' => $stationData['state'] ?? 'Unknown',
                'country' => $stationData['country'] ?? 'BR',
                'latitude' => (float)($stationData['latitude'] ?? 0),
                'longitude' => (float)($stationData['longitude'] ?? 0),
                'altitude' => $this->curatedAltitude($stationData),
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

        $this->attachOperators($stations, $metadata);

        usort($stations, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $stations;
    }

    /**
     * Resolve the 'operador' node of every station to the full operator payload.
     *
     * The de-para is made from the station name, mirroring the operator -> station
     * linking used by /operators, so both endpoints always agree on ownership.
     * Priority per station:
     * 1. the operator's explicit 'estacoes' list contains the station name
     * 2. the station name starts with the operator's 'estacao' prefix
     * 3. the station file's own 'operador' field matches an operator id
     *
     * When operators.json is missing or empty, one operator is synthesised per
     * station — the same records processOperators() falls back to — so a station
     * is never returned without an operator on a fresh install.
     *
     * Callers must pass the complete station list, because the nested 'stations'
     * and 'station_count' are filtered against it and have to stay identical to
     * what /operators returns for the same operator.
     *
     * @param  array<int,array<string,mixed>>  $stations
     * @param  array<string,array<string,mixed>>  $metadata  stations.json indexed by name
     */
    public function attachOperators(array &$stations, array $metadata = []): void
    {
        if ($stations === []) {
            return;
        }

        $metadata = $metadata ?: ($this->stationReader?->getStationsIndexed() ?? []);

        $rawOperators = array_values(array_filter(
            $this->operatorReader?->getOperators() ?? [],
            'is_array'
        ));

        if ($rawOperators === []) {
            foreach ($stations as &$station) {
                $station['operador'] = $this->buildFallbackOperator($station['name']);
            }
            unset($station);

            return;
        }

        // Normalise every operator once, so station and operator endpoints share
        // the exact same payload.
        $stationNames = array_column($stations, 'name');
        $operatorsById = [];

        foreach ($rawOperators as $rawOperator) {
            $operator = $this->buildOperator(
                $rawOperator,
                $this->operatorReader->getLinkedStationNames($rawOperator, $stationNames)
            );

            $operatorsById[(string) $operator['id']] = $operator;
        }

        foreach ($stations as &$station) {
            $stationName = $station['name'];

            $match = $this->operatorReader->findOperatorForStation($stationName, $rawOperators);

            if ($match !== null) {
                $station['operador'] = $operatorsById[(string) ($match['id'] ?? '')] ?? null;
                continue;
            }

            // Last resort: the station file may already declare the operator id.
            $declaredId = $metadata[$stationName]['operador'] ?? null;

            $station['operador'] = is_string($declaredId) && $declaredId !== ''
                ? ($operatorsById[$declaredId] ?? null)
                : null;
        }
        unset($station);
    }

    /**
     * Assemble a single station record.
     *
     * The 'operador' node is left null here and resolved afterwards by
     * attachOperators(), which turns the station name into the operator object.
     *
     * Latitude, longitude and altitude come from the position the index read
     * out of the station's most recent capture with a usable fix, which is a
     * measurement. The curated stations.json entry is the fallback for a
     * station whose captures never recorded one. No capture carries azimuth or
     * elevation, so those stay curated-only.
     *
     * @param  array<string,mixed>|null  $stationData
     * @param  array<string,mixed>|null  $aggregate
     * @return array<string,mixed>
     */
    protected function buildStation(string $stationName, ?array $stationData, ?array $aggregate): array
    {
        $geolocation = is_array($aggregate['geolocation'] ?? null) ? $aggregate['geolocation'] : null;

        return [
            'id' => $stationData['id'] ?? substr(md5($stationName), 0, 32),
            'name' => $stationName,
            'operador' => null,
            'city' => $stationData['city'] ?? 'Unknown',
            'state' => $stationData['state'] ?? 'Unknown',
            'country' => $stationData['country'] ?? 'BR',
            'latitude' => $this->measuredFloat($geolocation, 'latitude') ?? (float) ($stationData['latitude'] ?? 0),
            'longitude' => $this->measuredFloat($geolocation, 'longitude') ?? (float) ($stationData['longitude'] ?? 0),
            'altitude' => $this->measuredFloat($geolocation, 'altitude') ?? $this->curatedAltitude($stationData),
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
     * A coordinate measured by a capture, or null when it has none.
     *
     * The manifest is JSON, so a whole number comes back as an int on the next
     * run. Casting keeps altitude a float across the rebuild boundary instead
     * of the value changing shape between the first run and every later one.
     *
     * @param  array<string,mixed>|null  $geolocation
     */
    protected function measuredFloat(?array $geolocation, string $key): ?float
    {
        $value = $geolocation[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Altitude curated in stations.json, when the station file records one.
     *
     * @param  array<string,mixed>|null  $stationData
     */
    protected function curatedAltitude(?array $stationData): ?float
    {
        $altitude = $stationData['altitude'] ?? $stationData['_metadata']['altitude'] ?? null;

        return is_numeric($altitude) ? (float) $altitude : null;
    }

    /**
     * Process operators from JSON file, linking to stations.
     *
     * Falls back to one operator per station when operators.json is missing or
     * contains no usable entries, so a fresh install still lists operators
     * instead of returning an empty page.
     *
     * @param  array<int,array<string,mixed>>  $stations
     * @return array<int,array<string,mixed>>
     */
    public function processOperators(array $stations): array
    {
        $operators = [];

        $rawOperators = array_values(array_filter(
            $this->operatorReader?->getOperators() ?? [],
            'is_array'
        ));

        if ($rawOperators !== []) {
            $stationNames = array_column($stations, 'name');

            foreach ($rawOperators as $opData) {
                $operators[] = $this->buildOperator(
                    $opData,
                    $this->operatorReader->getLinkedStationNames($opData, $stationNames)
                );
            }
        } else {
            // Fallback: generate operators from stations
            foreach ($stations as $station) {
                $operators[] = $this->buildFallbackOperator($station['name']);
            }
        }

        // Sort by name
        usort($operators, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $operators;
    }

    /**
     * Normalise a raw operators.json entry into the public operator payload.
     *
     * @param  array<string,mixed>  $opData
     * @param  array<int,string>  $linkedStations
     * @return array<string,mixed>
     */
    protected function buildOperator(array $opData, array $linkedStations): array
    {
        $linkedStations = array_values($linkedStations);

        return array_merge([
            'id' => $opData['id'] ?? substr(md5($opData['email'] ?? ''), 0, 32),
            'name' => $opData['nome'] ?? $opData['name'] ?? 'Operador Desconhecido',
            'email' => $opData['email'] ?? '',
            'role' => $opData['role'] ?? 'operator',
            'visible' => (bool) ($opData['visivel'] ?? $opData['visible'] ?? true),
            'active' => (bool) ($opData['ativo'] ?? $opData['active'] ?? true),
            'station_count' => count($linkedStations),
            'stations' => $linkedStations,
        ], $this->operatorGeolocation($linkedStations));
    }

    /**
     * Position served for an operator, taken from the first station it owns.
     *
     * No capture records where an operator lives, so the position is the one
     * measured at that station: the index already resolved it from the
     * station's most recent capture with a usable fix.
     *
     * The station list is built in the operators.json order, so "first" is the
     * station the operator declared first. Fields are always present, and null
     * when that station has no measured position yet.
     *
     * @param  array<int,string>  $linkedStations
     * @return array{latitude:?float,longitude:?float,altitude:?float}
     */
    protected function operatorGeolocation(array $linkedStations): array
    {
        $first = $linkedStations[0] ?? null;

        $geolocation = $first !== null ? $this->index?->stationGeolocation($first) : null;

        return [
            'latitude' => $this->measuredFloat($geolocation, 'latitude'),
            'longitude' => $this->measuredFloat($geolocation, 'longitude'),
            'altitude' => $this->measuredFloat($geolocation, 'altitude'),
        ];
    }

    /**
     * Synthetic operator for a station, used when operators.json has no usable
     * entry. attachOperators() and processOperators() share it so the station and
     * operator endpoints never disagree.
     *
     * The name and address come from the operator the index recorded while
     * reading the station's captures, which is what a capture actually says.
     * Only a station with no metadata at all falls back to the generic
     * "Operador {estacao}" pair.
     *
     * @return array<string,mixed>
     */
    protected function buildFallbackOperator(string $stationName): array
    {
        $recorded = $this->index?->stationOperator($stationName);

        $recordedName = is_string($recorded['name'] ?? null) ? trim($recorded['name']) : '';
        $recordedEmail = is_string($recorded['email'] ?? null) ? trim($recorded['email']) : '';

        return array_merge([
            'id' => substr(md5("op_{$stationName}"), 0, 32),
            'name' => $recordedName !== '' ? $recordedName : "Operador {$stationName}",
            'email' => $recordedEmail !== ''
                ? $recordedEmail
                : strtolower($stationName) . '@bramonmeteor.org',
            'role' => 'operator',
            'visible' => true,
            'active' => true,
            'station_count' => 1,
            'stations' => [$stationName],
        ], $this->operatorGeolocation([$stationName]));
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