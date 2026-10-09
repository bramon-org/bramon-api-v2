<?php

namespace App\Services;

use Generator;
use RuntimeException;

/**
 * Persistent, on-disk index over the captures directory.
 *
 * Listing stations/operators only needs per-station aggregates, but the old
 * code derived them by loading every file in the tree on each request. This
 * class materialises those aggregates once (via the CLI) into a small manifest
 * and serves listing endpoints from it, so request memory is independent of
 * the number of files on disk.
 *
 * Layout (inside the configured index directory):
 *   - manifest.json      small: version, totals, date range, station aggregates
 *   - captures.ndjson    one capture per line, sorted by captured_at DESC
 *   - captures.lines     8-byte big-endian byte offset per capture line
 *   - by-station.ndjson  "station<TAB>lineIndex" sorted by station, then date
 *   - station-blocks.json station -> { offset, count } into by-station.ndjson
 *
 * Reads are seek-based and bounded by the page size, so pagination never
 * materialises the full dataset.
 */
class CaptureIndex
{
    /** Bumped whenever the on-disk layout changes. */
    public const VERSION = 4;

    /**
     * The artefacts a complete index consists of.
     *
     * The manifest is listed last on purpose: a rebuild writes every file to a
     * staging directory and renames them into place in this order, so the files
     * that back a listing are complete before the manifest that counts them
     * becomes visible.
     */
    public const FILES = [
        'captures.ndjson',
        'captures.lines',
        'by-station.ndjson',
        'station-blocks.json',
        'manifest.json',
    ];

    /** Magic bytes prefix for the fixed-width offset table. */
    private const OFFSET_BYTES = 8;

    /**
     * Bytes read from a metadata file when looking for the record tag.
     *
     * The <ufocapture_record> tag is the second line of every capture, so this
     * window is generous while still bounding the read to a fixed size.
     */
    private const METADATA_HEAD_BYTES = 16384;

    protected string $indexDir;

    protected ?array $manifest = null;

    /** @var array<string,array{count:int,first:?string,last:?string,operator:?array<string,mixed>,geolocation:?array<string,mixed>}> */
    protected ?array $stationAggregates = null;

    protected bool $autoBuild;

    public function __construct(string $indexDir, bool $autoBuild = false)
    {
        $this->indexDir = rtrim($indexDir, '/');
        $this->autoBuild = $autoBuild;
    }

    /* ------------------------------------------------------------------ */
    /* Paths                                                               */
    /* ------------------------------------------------------------------ */

    public function getIndexDir(): string
    {
        return $this->indexDir;
    }

    protected function path(string $file): string
    {
        return $this->pathIn($this->indexDir, $file);
    }

    protected function pathIn(string $dir, string $file): string
    {
        return rtrim($dir, '/') . '/' . $file;
    }

    /**
     * Directory a rebuild writes to before publishing.
     *
     * Kept beside the live index so the publishing renames stay within a single
     * filesystem, and suffixed with the pid so a manual run and a scheduled one
     * can never stage into the same place.
     */
    protected function stagingDir(): string
    {
        return $this->indexDir . '.building-' . getmypid();
    }

    public function manifestPath(): string
    {
        return $this->path('manifest.json');
    }

    protected function capturesPath(): string
    {
        return $this->path('captures.ndjson');
    }

    protected function linesPath(): string
    {
        return $this->path('captures.lines');
    }

    protected function byStationPath(): string
    {
        return $this->path('by-station.ndjson');
    }

    protected function stationBlocksPath(): string
    {
        return $this->path('station-blocks.json');
    }

    /* ------------------------------------------------------------------ */
    /* Availability                                                        */
    /* ------------------------------------------------------------------ */

    public function exists(): bool
    {
        return is_file($this->manifestPath())
            && is_file($this->capturesPath())
            && is_file($this->linesPath());
    }

    /**
     * Load the manifest, or null when the index is missing/incompatible.
     */
    public function manifest(): ?array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        if (!is_file($this->manifestPath())) {
            return null;
        }

        $raw = @file_get_contents($this->manifestPath());

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION) {
            return null;
        }

        return $this->manifest = $decoded;
    }

    /**
     * Total number of captures, or 0 when the index is unavailable.
     */
    public function totalCaptures(): int
    {
        return (int) ($this->manifest()['total_captures'] ?? 0);
    }

    public function totalFiles(): int
    {
        return (int) ($this->manifest()['total_files'] ?? 0);
    }

    public function totalStations(): int
    {
        return (int) ($this->manifest()['total_stations'] ?? 0);
    }

    public function dateRange(): array
    {
        $m = $this->manifest() ?? [];

        return [
            'earliest' => $m['earliest'] ?? null,
            'latest' => $m['latest'] ?? null,
        ];
    }

    public function generatedAt(): ?string
    {
        return $this->manifest()['generated_at'] ?? null;
    }

    /**
     * @return array<string,int>
     */
    public function capturesByMonth(): array
    {
        return $this->manifest()['captures_by_month'] ?? [];
    }

    /**
     * @return array<string,int>
     */
    public function capturesByStation(): array
    {
        return $this->manifest()['captures_by_station'] ?? [];
    }

    /* ------------------------------------------------------------------ */
    /* Station aggregates (the hot path for /stations and /operators)       */
    /* ------------------------------------------------------------------ */

    /**
     * Per-station count and first/last capture timestamps.
     *
     * @return array<string,array{count:int,first:?string,last:?string,operator:?array<string,mixed>,geolocation:?array<string,mixed>}>
     */
    public function stationAggregates(): array
    {
        if ($this->stationAggregates !== null) {
            return $this->stationAggregates;
        }

        $raw = $this->manifest()['station_aggregates'] ?? null;

        if (!is_array($raw)) {
            return $this->stationAggregates = [];
        }

        return $this->stationAggregates = $raw;
    }

    /**
     * Aggregates for one station, or null when unknown.
     */
    public function stationAggregate(string $station): ?array
    {
        return $this->stationAggregates()[$station] ?? null;
    }

    /**
     * The operator observed at a station, as read from the capture metadata
     * during the last rebuild, or null when no capture named one.
     *
     * This is the same name/e-mail pair a capture records, so a station never
     * has to be described by a synthetic operator when operators.json is
     * missing. operators.json still wins whenever it is present.
     *
     * @return array{id:string,name:string,email:string}|null
     */
    public function stationOperator(string $station): ?array
    {
        $operator = $this->stationAggregate($station)['operator'] ?? null;

        return is_array($operator) ? $operator : null;
    }

    /**
     * Position of a station, taken from its most recent capture that recorded a
     * usable fix, or null when none did.
     *
     * @return array{latitude:float,longitude:float,altitude:?float}|null
     */
    public function stationGeolocation(string $station): ?array
    {
        $geolocation = $this->stationAggregate($station)['geolocation'] ?? null;

        if (!is_array($geolocation)) {
            return null;
        }

        return [
            'latitude' => (float) ($geolocation['latitude'] ?? 0),
            'longitude' => (float) ($geolocation['longitude'] ?? 0),
            'altitude' => isset($geolocation['altitude']) && is_numeric($geolocation['altitude'])
                ? (float) $geolocation['altitude']
                : null,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Capture reads                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Number of capture lines stored in the index.
     */
    protected function lineCount(): int
    {
        $linesFile = $this->linesPath();

        if (!is_file($linesFile)) {
            return 0;
        }

        $size = @filesize($linesFile);

        if ($size === false) {
            return 0;
        }

        $count = intdiv((int) $size, self::OFFSET_BYTES);

        // A rebuild renames the offset table and the manifest in sequence, so a
        // request landing between the two can read a manifest that counts a
        // different number of lines than the table on disk. Trust the smaller
        // of the two: over-counting would page past the end of the file, while
        // under-counting only shortens the last page.
        $declared = $this->totalCaptures();

        return $declared > 0 ? min($count, $declared) : $count;
    }

    /**
     * Read a single capture by its 0-based line number.
     */
    protected function readLine(int $index): ?array
    {
        $offset = $this->offsetForLine($index);

        if ($offset === null) {
            return null;
        }

        $handle = @fopen($this->capturesPath(), 'rb');

        if ($handle === false) {
            return null;
        }

        if (fseek($handle, $offset) !== 0) {
            fclose($handle);

            return null;
        }

        $line = fgets($handle);
        fclose($handle);

        if ($line === false) {
            return null;
        }

        $decoded = json_decode($line, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Byte offset of a capture line, read from the fixed-width offset table.
     */
    protected function offsetForLine(int $index): ?int
    {
        if ($index < 0) {
            return null;
        }

        $handle = @fopen($this->linesPath(), 'rb');

        if ($handle === false) {
            return null;
        }

        $position = $index * self::OFFSET_BYTES;

        if (fseek($handle, $position) !== 0) {
            fclose($handle);

            return null;
        }

        $raw = fread($handle, self::OFFSET_BYTES);
        fclose($handle);

        if ($raw === false || strlen($raw) < self::OFFSET_BYTES) {
            return null;
        }

        $unpacked = unpack('J', $raw);

        if ($unpacked === false) {
            return null;
        }

        return (int) array_shift($unpacked);
    }

    /**
     * Line indices belonging to a station, in captured_at DESC order.
     *
     * @return array<int,int>
     */
    protected function lineIndicesForStation(string $station): array
    {
        if (!is_file($this->stationBlocksPath())) {
            return [];
        }

        $blocksRaw = @file_get_contents($this->stationBlocksPath());
        $blocks = $blocksRaw === false ? null : json_decode($blocksRaw, true);

        if (!is_array($blocks) || !isset($blocks[$station])) {
            return [];
        }

        $offset = (int) ($blocks[$station]['offset'] ?? 0);
        $count = (int) ($blocks[$station]['count'] ?? 0);

        if ($count <= 0) {
            return [];
        }

        $handle = @fopen($this->byStationPath(), 'rb');

        if ($handle === false) {
            return [];
        }

        if (fseek($handle, $offset) !== 0) {
            fclose($handle);

            return [];
        }

        $indices = [];
        $read = 0;

        // Bounded read: only this station's entries are ever held.
        while ($read < $count && ($line = fgets($handle)) !== false) {
            $read++;
            $tab = strrpos($line, "\t");

            if ($tab === false) {
                continue;
            }

            $indices[] = (int) trim(substr($line, $tab + 1));
        }

        fclose($handle);

        return $indices;
    }

    /**
     * Iterate capture lines lazily, applying filters while streaming.
     *
     * Only the requested page is retained, so memory stays O($perPage).
     *
     * @param  array{
     *     station?:?string,
     *     date_from?:?string,
     *     date_to?:?string,
     *     has_metadata?:?bool,
     *     has_preview?:?bool,
     *     query?:?string
     * }  $filters
     * @return array{items:array<int,array<string,mixed>>,total:int,total_pages:int}
     */
    public function capturesPage(int $page, int $perPage, array $filters = []): array
    {
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);

        $station = $filters['station'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $hasMetadata = $filters['has_metadata'] ?? null;
        $hasPreview = $filters['has_preview'] ?? null;
        $query = $filters['query'] ?? null;

        $qLower = $query !== null && $query !== '' ? strtolower($query) : null;

        $matched = 0;
        $collected = [];
        $start = ($page - 1) * $perPage;

        $hasFilters = ($dateFrom !== null && $dateFrom !== '')
            || ($dateTo !== null && $dateTo !== '')
            || $hasMetadata !== null
            || $hasPreview !== null
            || $qLower !== null;

        // Fast path: no station and no filters. The offset table already gives
        // the total, so only the requested page has to be read.
        if (($station === null || $station === '') && !$hasFilters) {
            $total = $this->lineCount();

            for ($i = $start; $i < min($start + $perPage, $total); $i++) {
                $capture = $this->readLine($i);

                if ($capture !== null) {
                    $collected[] = $capture;
                }
            }

            return [
                'items' => $collected,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ];
        }

        // Station requests resolve to a small block of line indices; otherwise
        // walk the offset table. Neither path materialises a large array.
        if ($station !== null && $station !== '') {
            foreach ($this->lineIndicesForStation($station) as $lineIndex) {
                $capture = $this->readLine($lineIndex);

                if ($capture === null) {
                    continue;
                }

                if (!$this->matches($capture, $dateFrom, $dateTo, $hasMetadata, $hasPreview, $qLower)) {
                    continue;
                }

                if ($matched >= $start && count($collected) < $perPage) {
                    $collected[] = $capture;
                }

                $matched++;
            }
        } else {
            $total = $this->lineCount();

            for ($lineIndex = 0; $lineIndex < $total; $lineIndex++) {
                $capture = $this->readLine($lineIndex);

                if ($capture === null) {
                    continue;
                }

                if (!$this->matches($capture, $dateFrom, $dateTo, $hasMetadata, $hasPreview, $qLower)) {
                    continue;
                }

                if ($matched >= $start && count($collected) < $perPage) {
                    $collected[] = $capture;
                }

                $matched++;
            }
        }

        return [
            'items' => $collected,
            'total' => $matched,
            'total_pages' => (int) ceil($matched / $perPage),
        ];
    }

    /**
     * Fetch one capture by its generated id.
     *
     * Streams the index until a match is found, keeping memory O(1).
     */
    public function captureById(string $captureId): ?array
    {
        $handle = @fopen($this->capturesPath(), 'rb');

        if ($handle === false) {
            return null;
        }

        while (($line = fgets($handle)) !== false) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && ($decoded['id'] ?? null) === $captureId) {
                fclose($handle);

                return $decoded;
            }
        }

        fclose($handle);

        return null;
    }

    /**
     * Iterate a page of captures for one station, newest first.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,total_pages:int}
     */
    public function stationCapturesPage(
        string $station,
        int $page,
        int $perPage,
        array $filters = []
    ): array {
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);

        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $hasMetadata = $filters['has_metadata'] ?? null;
        $hasPreview = $filters['has_preview'] ?? null;
        $query = $filters['query'] ?? null;
        $qLower = $query !== null && $query !== '' ? strtolower($query) : null;

        $indices = $this->lineIndicesForStation($station);

        $matched = 0;
        $collected = [];
        $start = ($page - 1) * $perPage;

        foreach ($indices as $lineIndex) {
            $capture = $this->readLine($lineIndex);

            if ($capture === null) {
                continue;
            }

            if (!$this->matches($capture, $dateFrom, $dateTo, $hasMetadata, $hasPreview, $qLower)) {
                continue;
            }

            if ($matched >= $start && count($collected) < $perPage) {
                $collected[] = $capture;
            }

            $matched++;
        }

        return [
            'items' => $collected,
            'total' => $matched,
            'total_pages' => (int) ceil($matched / $perPage),
        ];
    }

    /**
     * Per-station statistics derived from the stored aggregates.
     *
     * @return array<string,mixed>|null
     */
    public function stationStats(string $station): ?array
    {
        $aggregate = $this->stationAggregate($station);

        if ($aggregate === null) {
            return null;
        }

        return [
            'station' => $station,
            'total_captures' => $aggregate['count'],
            'first_capture' => $aggregate['first'],
            'last_capture' => $aggregate['last'],
            'captures_by_month' => $this->capturesByMonthForStation($station),
        ];
    }

    /**
     * @return array<string,int>
     */
    public function capturesByMonthForStation(string $station): array
    {
        $indices = $this->lineIndicesForStation($station);
        $months = [];

        foreach ($indices as $lineIndex) {
            $capture = $this->readLine($lineIndex);

            if ($capture === null) {
                continue;
            }

            $monthKey = substr((string) ($capture['capture_date'] ?? ''), 0, 6);

            if ($monthKey !== '') {
                $months[$monthKey] = ($months[$monthKey] ?? 0) + 1;
            }
        }

        ksort($months);

        return $months;
    }

    /**
     * Filter predicate shared by the paging methods.
     *
     * @param  array<string,mixed>  $capture
     */
    protected function matches(
        array $capture,
        ?string $dateFrom,
        ?string $dateTo,
        ?bool $hasMetadata,
        ?bool $hasPreview,
        ?string $qLower
    ): bool {
        $capturedAt = (string) ($capture['captured_at'] ?? '');

        if ($dateFrom !== null && $dateFrom !== '' && $capturedAt < $dateFrom) {
            return false;
        }

        if ($dateTo !== null && $dateTo !== '' && $capturedAt > $dateTo . ' 23:59:59') {
            return false;
        }

        if ($hasMetadata !== null && (bool) ($capture['has_metadata'] ?? false) !== $hasMetadata) {
            return false;
        }

        if ($hasPreview !== null && (bool) ($capture['has_preview'] ?? false) !== $hasPreview) {
            return false;
        }

        if ($qLower !== null && !CaptureIndex::captureMatchesQuery($capture, $qLower)) {
            return false;
        }

        return true;
    }

    /**
     * Free-text match across station, dates and file metadata.
     *
     * @param  array<string,mixed>  $capture
     */
    public static function captureMatchesQuery(array $capture, string $qLower): bool
    {
        $station = strtolower((string) ($capture['station'] ?? ''));

        if ($station !== '' && str_contains($station, $qLower)) {
            return true;
        }

        $query = ltrim($qLower, '0');

        if ($query !== ''
            && (str_contains((string) ($capture['capture_date'] ?? ''), $query)
                || str_contains((string) ($capture['captured_at'] ?? ''), $query))) {
            return true;
        }

        foreach ((array) ($capture['files'] ?? []) as $file) {
            $filename = strtolower((string) ($file['filename'] ?? ''));
            $type = strtolower((string) ($file['type'] ?? ''));

            if (($filename !== '' && str_contains($filename, $qLower))
                || ($type !== '' && str_contains($type, $qLower))) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------ */
    /* Build                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Rebuild the index from the captures directory using bounded memory.
     *
     * The index is staged beside the live one and renamed into place only once
     * every artefact is complete. Truncating the live files in place instead
     * would let a request landing mid-rebuild read a half-written offset table:
     * `exists()` would still be true, so the listing would answer with a wrong
     * total and short or undecodable records instead of failing cleanly.
     *
     * @param  callable|null  $progress  Receives (int $filesSeen, string $stage).
     * @return array<string,mixed> Summary written to the manifest.
     */
    public function rebuild(FileSystemReader $reader, ?callable $progress = null, int $chunkSize = 20000, ?string $tempDir = null): array
    {
        if (!is_dir($this->indexDir) && !@mkdir($this->indexDir, 0775, true) && !is_dir($this->indexDir)) {
            throw new RuntimeException("Unable to create index directory: {$this->indexDir}");
        }

        if (!is_writable($this->indexDir)) {
            throw new RuntimeException("Index directory is not writable: {$this->indexDir}");
        }

        $stagingDir = $this->stagingDir();

        if (!@mkdir($stagingDir, 0775, true) && !is_dir($stagingDir)) {
            throw new RuntimeException("Unable to create staging directory: {$stagingDir}");
        }

        try {
            $manifest = $this->build($reader, $progress, $chunkSize, $stagingDir, $tempDir);
            $this->publish($stagingDir);
        } finally {
            // Whether the build or the publish failed, the previous index has to
            // keep serving traffic: only the staging directory is abandoned.
            $this->discardStaging($stagingDir);
        }

        return $manifest;
    }

    /**
     * Write a complete index into $stagingDir without touching the live one.
     *
     * @return array<string,mixed> Summary destined for the manifest.
     */
    protected function build(FileSystemReader $reader, ?callable $progress, int $chunkSize, string $stagingDir, ?string $tempDir = null): array
    {
        // Pass 1: stream files, coalescing them into captures. Spill sorted
        // chunks so the whole capture set is never held in memory.
        $sorter = new ExternalSort($chunkSize, true, $tempDir);

        /** @var array<string,array<string,mixed>> $pending */
        $pending = [];
        $filesSeen = 0;
        $totalFiles = 0;

        // Facts extracted from XML metadata, cached per capture so a file is
        // only opened once no matter how many files share it.
        /** @var array<string,array{operator:?array<string,string>,geolocation:?array<string,?float>}> $metadataFacts */
        $metadataFacts = [];

        foreach ($reader->getFilesGenerator(false) as $file) {
            $filesSeen++;

            if ($filesSeen % 5000 === 0 && $progress !== null) {
                $progress($filesSeen, 'indexing');
            }

            $key = "{$file['station']}_{$file['date']}_{$file['time']}";

            if (($file['type'] ?? null) === 'metadata' && isset($file['path'])) {
                $facts = $this->readMetadataFacts($file['path']);
                if (!isset($metadataFacts[$key])) {
                    $metadataFacts[$key] = $facts;
                } else {
                    // Merge operator: prefer non-null operator from any file
                    if ($metadataFacts[$key]['operator'] === null && $facts['operator'] !== null) {
                        $metadataFacts[$key]['operator'] = $facts['operator'];
                    }
                    // Merge geolocation: prefer non-null geolocation from any file
                    if ($metadataFacts[$key]['geolocation'] === null && $facts['geolocation'] !== null) {
                        $metadataFacts[$key]['geolocation'] = $facts['geolocation'];
                    }
                }
            }

            if (!isset($pending[$key])) {
                if (count($pending) >= $chunkSize) {
                    $this->flushCaptures($pending, $sorter);
                    $pending = [];
                }

                $pending[$key] = $this->newCapture($file);
            }

            $this->attachFile($pending[$key], $file);
            $totalFiles++;
        }

        if ($pending !== []) {
            $this->flushCaptures($pending, $sorter);
            $pending = [];
        }

        if ($progress !== null) {
            $progress($filesSeen, 'sorting');
        }

        // Pass 2: write the sorted stream, offset table and station blocks.
        $capturesHandle = @fopen($this->pathIn($stagingDir, 'captures.ndjson'), 'wb');
        $linesHandle = @fopen($this->pathIn($stagingDir, 'captures.lines'), 'wb');

        if ($capturesHandle === false || $linesHandle === false) {
            throw new RuntimeException("Unable to open index output files in {$this->indexDir}");
        }

        $byMonth = [];
        $aggregates = [];
        $earliest = null;
        $latest = null;
        $totalCaptures = 0;
        $offset = 0;
        $lineIndex = 0;

        $byStationRecords = [];

        foreach ($sorter->sorted() as [$sortKey, $capture]) {
            $captureKey = $capture['station'] . '_' . $capture['capture_date'] . '_' . $capture['capture_time'];

            $line = json_encode($capture, JSON_UNESCAPED_UNICODE);

            if ($line === false) {
                continue;
            }

            $line .= "\n";

            fwrite($capturesHandle, $line);
            fflush($capturesHandle);
            if (function_exists('fsync')) {
                @fsync($capturesHandle);
            }

            fwrite($linesHandle, pack('J', $offset));
            fflush($linesHandle);
            if (function_exists('fsync')) {
                @fsync($linesHandle);
            }

            $offset += strlen($line);

            $capturedAt = (string) $capture['captured_at'];
            $station = (string) $capture['station'];
            $monthKey = substr((string) $capture['capture_date'], 0, 6);

            $byMonth[$monthKey] = ($byMonth[$monthKey] ?? 0) + 1;

            if (!isset($aggregates[$station])) {
                $aggregates[$station] = [
                    'count' => 0,
                    'first' => $capturedAt,
                    'last' => $capturedAt,
                    'operator' => null,
                    'geolocation' => null,
                ];
            }

            $aggregates[$station]['count']++;

            if ($capturedAt < $aggregates[$station]['first']) {
                $aggregates[$station]['first'] = $capturedAt;
            }

            if ($capturedAt > $aggregates[$station]['last']) {
                $aggregates[$station]['last'] = $capturedAt;
            }

            if ($earliest === null || $capturedAt < $earliest) {
                $earliest = $capturedAt;
            }

            if ($latest === null || $capturedAt > $latest) {
                $latest = $capturedAt;
            }

            // Pass 2 walks the captures newest first, so the first capture that
            // carries an operator or a usable fix is the most recent one that
            // does. Later captures only fill what is still missing.
            $facts = $metadataFacts[$captureKey] ?? null;

            if ($facts !== null) {
                if ($aggregates[$station]['operator'] === null && $facts['operator'] !== null) {
                    $aggregates[$station]['operator'] = $facts['operator'];
                }

                if ($aggregates[$station]['geolocation'] === null && $facts['geolocation'] !== null) {
                    $aggregates[$station]['geolocation'] = $facts['geolocation'];
                }
            }

            $byStationRecords[$station][] = $lineIndex;

            $totalCaptures++;
            $lineIndex++;
        }

        $this->sync($capturesHandle);
        fclose($capturesHandle);

        $this->sync($linesHandle);
        fclose($linesHandle);

        unset($metadataFacts);

        if ($progress !== null) {
            $progress($filesSeen, 'writing-station-index');
        }

        // Station blocks: write each station's line indices contiguously so a
        // station page can be answered with two seeks.
        $blocks = [];
        $blockHandle = @fopen($this->pathIn($stagingDir, 'by-station.ndjson'), 'wb');
        $blockOffset = 0;

        ksort($aggregates);

        foreach ($byStationRecords as $station => $indices) {
            $payload = '';

            foreach ($indices as $i) {
                $payload .= $station . "\t" . $i . "\n";
            }

            fwrite($blockHandle, $payload);
        fflush($blockHandle);
        if (function_exists('fsync')) {
            @fsync($blockHandle);
        }

            $blocks[$station] = [
                'offset' => $blockOffset,
                'count' => count($indices),
            ];

            $blockOffset += strlen($payload);
        }

        $this->sync($blockHandle);
        fclose($blockHandle);

        unset($byStationRecords);

        @file_put_contents(
            $this->pathIn($stagingDir, 'station-blocks.json'),
            json_encode($blocks, JSON_UNESCAPED_UNICODE)
        );

        ksort($byMonth);

        $manifest = [
            'version' => self::VERSION,
            'generated_at' => date('c'),
            'total_captures' => $totalCaptures,
            'total_files' => $totalFiles,
            'total_stations' => count($aggregates),
            'earliest' => $earliest,
            'latest' => $latest,
            'captures_by_month' => $byMonth,
            'station_aggregates' => $aggregates,
        ];

        $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE);
        $manifestPath = $this->pathIn($stagingDir, 'manifest.json');
        
        if (file_put_contents($manifestPath, $manifestJson, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write manifest to {$manifestPath}");
        }

        $handle = @fopen($manifestPath, 'r+');
        if ($handle) {
            fflush($handle);
            if (function_exists('fsync')) {
                @fsync($handle);
            }
            fclose($handle);
        }

        if ($progress !== null) {
            $progress($filesSeen, 'done');
        }

        return $manifest;

    }

    /**
     * Attempt to write a file multiple times to reduce IO write failures.
     *
     * @param string $filename
     * @param string $data
     * @param int $attempts
     * @param int $delayMs
     * @return bool
     */
    protected function retryFilePutContents(string $filename, string $data, int $attempts = 3, int $delayMs = 100): bool
    {
        for ($i = 0; $i < $attempts; $i++) {
            $result = @file_put_contents($filename, $data);

            if ($result !== false) {
                return true;
            }

            usleep($delayMs * 1000);
        }

        return false;
    }

    /**
     * Rename the staged artefacts into the live index.
     *
     * `rename()` within a directory is atomic, so a concurrent reader opens
     * either the old file or the new one and never a partially written one.
     * `self::FILES` puts the manifest last, so the files backing a listing are
     * complete before the manifest that counts them becomes visible.
     */
    protected function publish(string $stagingDir): void
    {
        foreach (self::FILES as $file) {
            $from = $this->pathIn($stagingDir, $file);
            $to = $this->path($file);

            if (!is_file($from)) {
                \Log::warning("Index publish failed: Source file missing at {$from}");
                continue;
            }

            if (!rename($from, $to)) {
                $error = error_get_last();
                $message = $error['message'] ?? 'Unknown error';
                \Log::error("Index publish failed: Unable to rename {$from} to {$to}. Error: {$message}");
                throw new RuntimeException("Unable to publish index file: {$to}. Error: {$message}");
            }
        }

        $this->manifest = null;
        $this->stationAggregates = null;
    }

    /**
     * Remove a staging directory and anything left in it.
     */
    protected function discardStaging(string $stagingDir): void
    {
        if (!is_dir($stagingDir)) {
            return;
        }

        foreach (self::FILES as $file) {
            @unlink($this->pathIn($stagingDir, $file));
        }

        @rmdir($stagingDir);
    }

    /**
     * Flush a write handle to disk before it is closed.
     *
     * Without this the kernel may still hold the tail of a large file, and a
     * rename that follows could publish a file that is short on disk.
     */
    protected function sync(mixed $handle): void
    {
        if (is_resource($handle) && function_exists('fsync')) {
            @fsync($handle);
        }
    }

    /**
     * Seed a capture record from its first file.
     *
     * @param  array<string,mixed>  $file
     * @return array<string,mixed>
     */
    protected function newCapture(array $file): array
    {
        $station = (string) $file['station'];
        $date = (string) $file['date'];
        $time = (string) $file['time'];

        $capturedAt = null;
        $dt = \DateTime::createFromFormat('YmdHis', $date . $time);

        if ($dt !== false) {
            $capturedAt = $dt->format('Y-m-d H:i:s');
        }

        return [
            'id' => substr(md5("{$station}_{$date}_{$time}"), 0, 32),
            'station' => $station,
            'captured_at' => $capturedAt ?? "{$date} 00:00:00",
            'capture_date' => $date,
            'capture_time' => $time,
            'files' => [],
            'file_count' => 0,
            'has_metadata' => false,
            'has_preview' => false,
            'has_thumbnail' => false,
            'class' => null,
            'fs' => null,
            'fe' => null,
            'sec' => null,
            'av' => null,
            'mag' => null,
            'analyzed' => false,
        ];
    }

    /**
     * Attach a file to a capture record and update its flags.
     *
     * @param  array<string,mixed>  $capture
     * @param  array<string,mixed>  $file
     */
    protected function attachFile(array &$capture, array $file): void
    {
        $type = (string) $file['type'];

        $capture['files'][] = [
            'url' => $file['rel_path'],
            'type' => $type,
            'filename' => $file['filename'],
            'extension' => $file['extension'],
            'size' => $file['size'] ?? null,
            'modified_at' => $file['modified_at'] ?? null,
        ];

        $capture['file_count'] = count($capture['files']);

        if ($type === 'metadata') {
            $capture['has_metadata'] = true;
        } elseif ($type === 'preview') {
            $capture['has_preview'] = true;
        } elseif ($type === 'thumbnail') {
            $capture['has_thumbnail'] = true;
        }
    }

    /**
     * Move buffered captures into the external sorter.
     *
     * @param  array<string,array<string,mixed>>  $pending
     */
    protected function flushCaptures(array &$pending, ExternalSort $sorter): void
    {
        foreach ($pending as $capture) {
            // Sort newest first to match the API's default ordering.
            $sorter->add((string) $capture['captured_at'], $capture);
        }

        $pending = [];
    }

    /**
     * Read the operator and the position a capture recorded in its metadata.
     *
     * The metadata is the only place either is ever recorded: the record tag
     * carries the observer, the station code and the coordinates, and no
     * capture carries an e-mail, so the address is derived from the name. Only
     * the head of the file is read, because the record tag is always at the top.
     *
     * @return array{operator:?array<string,string>,geolocation:?array<string,?float>}
     */
    protected function readMetadataFacts(string $path): array
    {
        $none = ['operator' => null, 'geolocation' => null];

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return $none;
        }

        $head = fread($handle, self::METADATA_HEAD_BYTES);
        fclose($handle);

        if (!is_string($head) || $head === '') {
            return $none;
        }

        if (preg_match('/<ufocapture_record\b[^>]*>/', $head, $tagMatch) !== 1) {
            return $none;
        }

        $tag = $tagMatch[0];

        return [
            'operator' => $this->operatorFromTag($tag),
            'geolocation' => $this->geolocationFromTag($tag),
        ];
    }

    /**
     * Pull the observer and the station code out of a record tag.
     *
     * A capture with no observer still identifies the operator by station code,
     * which is what `lid` holds.
     *
     * @return array{id:string,name:string,email:string}|null
     */
    protected function operatorFromTag(string $tag): ?array
    {
        $observer = preg_match('/\bobserver="([^"]*)"/', $tag, $match) === 1 ? trim($match[1]) : '';
        $lid = preg_match('/\blid="([^"]*)"/', $tag, $match) === 1 ? trim($match[1]) : '';

        $name = $observer !== '' ? $observer : $lid;

        if ($name === '') {
            return null;
        }

        return [
            'id' => $lid !== '' ? $lid : $name,
            'name' => $name,
            'email' => OperatorReader::emailFromName($name),
        ];
    }

    /**
     * Pull the coordinates out of a record tag, discarding unusable fixes.
     *
     * A capture recorded before the station got a fix, or with the GPS off,
     * stores 0/0. That is not a location, so it never becomes the station's
     * position and an older capture with a real fix is used instead.
     *
     * @return array{latitude:float,longitude:float,altitude:?float}|null
     */
    protected function geolocationFromTag(string $tag): ?array
    {
        $hasLat = preg_match('/\blat="([^"]*)"/', $tag, $latMatch) === 1;
        $hasLng = preg_match('/\blng="([^"]*)"/', $tag, $lngMatch) === 1;

        if (!$hasLat || !$hasLng || !is_numeric(trim($latMatch[1])) || !is_numeric(trim($lngMatch[1]))) {
            return null;
        }

        $latitude = (float) trim($latMatch[1]);
        $longitude = (float) trim($lngMatch[1]);

        if (($latitude == 0.0 && $longitude == 0.0)
            || $latitude < -90.0 || $latitude > 90.0
            || $longitude < -180.0 || $longitude > 180.0) {
            return null;
        }

        $altitude = null;

        if (preg_match('/\balt="([^"]*)"/', $tag, $altMatch) === 1 && is_numeric(trim($altMatch[1]))) {
            $altitude = (float) trim($altMatch[1]);
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'altitude' => $altitude,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Maintenance                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Delete every index artefact.
     */
    public function flush(): void
    {
        foreach (self::FILES as $file) {
            $path = $this->path($file);

            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->manifest = null;
        $this->stationAggregates = null;
    }

    /**
     * Approximate on-disk size of the index in bytes.
     */
    public function diskUsage(): int
    {
        $bytes = 0;

        foreach (self::FILES as $file) {
            $path = $this->path($file);

            if (is_file($path)) {
                $bytes += (int) @filesize($path);
            }
        }

        return $bytes;
    }
}
