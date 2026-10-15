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
    public const VERSION = 5;

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
     * Checkpoint persisted during a resumable build.
     *
     * Written to the staging directory after each station, it records which
     * stations are already spilled to sorted chunks so an interrupted build
     * restarts after the last completed station instead of from scratch.
     */
    public const CHECKPOINT_FILE = 'checkpoint.json';

    /** Subdirectory of a resumable staging dir holding the sorted chunk runs. */
    protected const CHUNKS_DIR = 'chunks';

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
     * filesystem. A resumable build (--resume) uses a fixed directory so a retry
     * finds the previous checkpoint and its sorted chunks; a regular build keeps
     * the pid suffix so a manual run and a scheduled one can never stage into
     * the same place.
     */
    protected function stagingDir(bool $resumable = false): string
    {
        if ($resumable) {
            return $this->indexDir . '.building';
        }

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
            && is_file($this->linesPath())
            && $this->manifest() !== null;
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
    protected function readLine(int $index, $capturesHandle = null, $linesHandle = null): ?array
    {
        $ownsCapturesHandle = $capturesHandle === null;

        if ($ownsCapturesHandle) {
            $capturesHandle = @fopen($this->capturesPath(), 'rb');
        }

        if ($capturesHandle === false) {
            return null;
        }

        try {
            $offset = $this->offsetForLine($index, $linesHandle);

            if ($offset === null) {
                return null;
            }

            if (fseek($capturesHandle, $offset) !== 0) {
                return null;
            }

            $line = fgets($capturesHandle);

            if ($line === false) {
                return null;
            }

            $decoded = json_decode($line, true);

            return is_array($decoded) ? $decoded : null;
        } finally {
            if ($ownsCapturesHandle && is_resource($capturesHandle)) {
                fclose($capturesHandle);
            }
        }
    }

    /**
     * Byte offset of a capture line, read from the fixed-width offset table.
     */
    protected function offsetForLine(int $index, $linesHandle = null): ?int
    {
        if ($index < 0) {
            return null;
        }

        $ownsLinesHandle = $linesHandle === null;

        if ($ownsLinesHandle) {
            $linesHandle = @fopen($this->linesPath(), 'rb');
        }

        if ($linesHandle === false) {
            return null;
        }

        try {
            $position = $index * self::OFFSET_BYTES;

            if (fseek($linesHandle, $position) !== 0) {
                return null;
            }

            $raw = fread($linesHandle, self::OFFSET_BYTES);

            if ($raw === false || strlen($raw) < self::OFFSET_BYTES) {
                return null;
            }

            $unpacked = unpack('J', $raw);

            if ($unpacked === false) {
                return null;
            }

            return (int) array_shift($unpacked);
        } finally {
            if ($ownsLinesHandle && is_resource($linesHandle)) {
                fclose($linesHandle);
            }
        }
    }

    /**
     * Line indices belonging to a station, in captured_at DESC order.
     *
     * @return array<int,int>
     */
    protected function lineIndicesForStation(string $station): array
    {
        $block = $this->stationBlock($station);

        if ($block === null) {
            return [];
        }

        $offset = $block['offset'];
        $count = $block['count'];

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
     * Read only one station page of line indices while streaming past earlier entries.
     *
     * @return array{indices:array<int,int>,total:int}
     */
    protected function lineIndicesPageForStation(string $station, int $start, int $limit): array
    {
        $block = $this->stationBlock($station);

        if ($block === null) {
            return ['indices' => [], 'total' => 0];
        }

        $total = $block['count'];

        if ($start >= $total || $limit <= 0) {
            return ['indices' => [], 'total' => $total];
        }

        $handle = @fopen($this->byStationPath(), 'rb');

        if ($handle === false) {
            return ['indices' => [], 'total' => $total];
        }

        if (fseek($handle, $block['offset']) !== 0) {
            fclose($handle);

            return ['indices' => [], 'total' => $total];
        }

        $indices = [];
        $read = 0;

        while ($read < min($start + $limit, $total) && ($line = fgets($handle)) !== false) {
            if ($read >= $start) {
                $tab = strrpos($line, "\t");

                if ($tab !== false) {
                    $indices[] = (int) trim(substr($line, $tab + 1));
                }
            }

            $read++;
        }

        fclose($handle);

        return ['indices' => $indices, 'total' => $total];
    }

    /**
     * @return array{offset:int,count:int}|null
     */
    protected function stationBlock(string $station): ?array
    {
        if (!is_file($this->stationBlocksPath())) {
            return null;
        }

        $blocksRaw = @file_get_contents($this->stationBlocksPath());
        $blocks = $blocksRaw === false ? null : json_decode($blocksRaw, true);

        if (!is_array($blocks) || !isset($blocks[$station]) || !is_array($blocks[$station])) {
            return null;
        }

        return [
            'offset' => (int) ($blocks[$station]['offset'] ?? 0),
            'count' => max(0, (int) ($blocks[$station]['count'] ?? 0)),
        ];
    }

    /**
     * Iterate capture lines lazily, applying filters while streaming.
     *
     * Only the requested page is retained, so memory stays O($perPage).
     *
     * @param  array{
    *     station?:?string,
        *     stations?:array<int,string>,
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
        $stations = $filters['stations'] ?? null;
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
            || (is_array($stations) && $stations !== [])
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

        if ($station !== null && $station !== '' && !$hasFilters) {
            $stationPage = $this->lineIndicesPageForStation($station, $start, $perPage);
            $items = [];

            foreach ($stationPage['indices'] as $lineIndex) {
                $capture = $this->readLine($lineIndex);

                if ($capture !== null) {
                    $items[] = $capture;
                }
            }

            return [
                'items' => $items,
                'total' => $stationPage['total'],
                'total_pages' => (int) ceil($stationPage['total'] / $perPage),
            ];
        }

        $capturesHandle = @fopen($this->capturesPath(), 'rb');
        $linesHandle = @fopen($this->linesPath(), 'rb');

        if ($capturesHandle === false || $linesHandle === false) {
            if (is_resource($capturesHandle)) {
                fclose($capturesHandle);
            }
            if (is_resource($linesHandle)) {
                fclose($linesHandle);
            }
            $capturesHandle = null;
            $linesHandle = null;
        }

        try {
            // Station requests resolve to a block of line indices; otherwise
            // walk the offset table. Reuse both file handles across the scan.
            if ($station !== null && $station !== '') {
                foreach ($this->lineIndicesForStation($station) as $lineIndex) {
                    $capture = $this->readLine($lineIndex, $capturesHandle, $linesHandle);

                    if ($capture === null) {
                        continue;
                    }

                    if (!$this->matches($capture, $dateFrom, $dateTo, $hasMetadata, $hasPreview, $qLower, $stations)) {
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
                    $capture = $this->readLine($lineIndex, $capturesHandle, $linesHandle);

                    if ($capture === null) {
                        continue;
                    }

                    if (!$this->matches($capture, $dateFrom, $dateTo, $hasMetadata, $hasPreview, $qLower, $stations)) {
                        continue;
                    }

                    if ($matched >= $start && count($collected) < $perPage) {
                        $collected[] = $capture;
                    }

                    $matched++;
                }
            }
        } finally {
            if (is_resource($capturesHandle)) {
                fclose($capturesHandle);
            }
            if (is_resource($linesHandle)) {
                fclose($linesHandle);
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

        $hasFilters = ($dateFrom !== null && $dateFrom !== '')
            || ($dateTo !== null && $dateTo !== '')
            || $hasMetadata !== null
            || $hasPreview !== null
            || $qLower !== null;

        if (!$hasFilters) {
            $start = ($page - 1) * $perPage;
            $stationPage = $this->lineIndicesPageForStation($station, $start, $perPage);
            $items = [];

            foreach ($stationPage['indices'] as $lineIndex) {
                $capture = $this->readLine($lineIndex);

                if ($capture !== null) {
                    $items[] = $capture;
                }
            }

            return [
                'items' => $items,
                'total' => $stationPage['total'],
                'total_pages' => (int) ceil($stationPage['total'] / $perPage),
            ];
        }

        $indices = $this->lineIndicesForStation($station);

        $matched = 0;
        $collected = [];
        $start = ($page - 1) * $perPage;

        $capturesHandle = @fopen($this->capturesPath(), 'rb');
        $linesHandle = @fopen($this->linesPath(), 'rb');

        if ($capturesHandle === false || $linesHandle === false) {
            if (is_resource($capturesHandle)) {
                fclose($capturesHandle);
            }
            if (is_resource($linesHandle)) {
                fclose($linesHandle);
            }
            $capturesHandle = null;
            $linesHandle = null;
        }

        try {
            foreach ($indices as $lineIndex) {
                $capture = $this->readLine($lineIndex, $capturesHandle, $linesHandle);

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
        } finally {
            if (is_resource($capturesHandle)) {
                fclose($capturesHandle);
            }
            if (is_resource($linesHandle)) {
                fclose($linesHandle);
            }
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
        ?string $qLower,
        ?array $stations = null
    ): bool {
        if (is_array($stations) && $stations !== []
            && !in_array($capture['station'] ?? null, $stations, true)) {
            return false;
        }

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
     * Large trees should use `['resume' => true]`: the scan then proceeds
     * station by station, spilling sorted chunks into the staging directory and
     * recording a checkpoint after each station. An interrupted build keeps that
     * staging directory, and the next run with `resume` continues after the last
     * completed station instead of starting over.
     *
     * `['since_days' => X]` (or `['since' => 'YYYY-MM-DD']`) indexes only
     * captures on/after that date, by filename date, for a fast partial index.
     * The applied filter is recorded in the manifest under `filter`.
     *
     * @param  callable|null  $progress  Receives (int $filesSeen, string $stage).
     * @param  array{resume?:bool,fresh?:bool,since_days?:int,since?:string,stations?:array<int,string>|string}  $options
     * @return array<string,mixed> Summary written to the manifest.
     */
    public function rebuild(FileSystemReader $reader, ?callable $progress = null, int $chunkSize = 20000, ?string $tempDir = null, array $options = []): array
    {
        if (!is_dir($this->indexDir) && !@mkdir($this->indexDir, 0775, true) && !is_dir($this->indexDir)) {
            throw new RuntimeException("Unable to create index directory: {$this->indexDir}");
        }

        if (!is_writable($this->indexDir)) {
            throw new RuntimeException("Index directory is not writable: {$this->indexDir}");
        }

        $resume = (bool) ($options['resume'] ?? false);
        $fresh = (bool) ($options['fresh'] ?? false);
        $sinceInfo = self::normalizeSinceDate($options);

        $stagingDir = $this->stagingDir($resume);

        if ($resume && $fresh) {
            $this->discardStaging($stagingDir);
        }

        if (!@mkdir($stagingDir, 0775, true) && !is_dir($stagingDir)) {
            throw new RuntimeException("Unable to create staging directory: {$stagingDir}");
        }

        $ok = false;

        try {
            $manifest = $this->build($reader, $progress, $chunkSize, $stagingDir, $tempDir, $resume, $sinceInfo, $options);
            $this->publish($stagingDir);
            $this->manifest = null;
            $this->stationAggregates = null;
            $ok = true;
        } finally {
            // A failed resumable build keeps its staging directory (sorted
            // chunks plus checkpoint) so the next run with `resume` continues
            // where this one stopped. Every other outcome discards staging, so
            // the previous index keeps serving traffic and no debris remains.
            if ($ok || !$resume) {
                $this->discardStaging($stagingDir);
            }
        }

        return $manifest;
    }

    /**
     * Normalise the date-filter options into a filename-comparable form.
     *
     * Filenames carry the capture date as YYYYMMDD, so the cutoff is kept in
     * that shape for a plain string comparison, alongside the human-readable
     * form stored in the manifest.
     *
     * @param  array{since_days?:mixed,sinceDays?:mixed,since?:mixed}  $options
     * @return array{since_date:?string,since:?string,since_days:?int}
     */
    protected static function normalizeSinceDate(array $options): array
    {
        $none = ['since_date' => null, 'since' => null, 'since_days' => null];

        $since = $options['since'] ?? null;

        if ($since !== null && $since !== '') {
            $digits = str_replace('-', '', (string) $since);

            $dt = \DateTime::createFromFormat('Ymd', $digits);

            if ($dt === false || $dt->format('Ymd') !== $digits) {
                throw new RuntimeException(
                    "Invalid date filter '{$since}': expected YYYY-MM-DD or YYYYMMDD."
                );
            }

            return [
                'since_date' => $digits,
                'since' => $dt->format('Y-m-d'),
                'since_days' => null,
            ];
        }

        $sinceDays = $options['since_days'] ?? $options['sinceDays'] ?? null;

        if ($sinceDays !== null && $sinceDays !== '') {
            $days = (int) $sinceDays;

            if ($days < 0) {
                throw new RuntimeException("Invalid --since-days value '{$sinceDays}': expected 0 or more.");
            }

            $digits = date('Ymd', strtotime("-{$days} days"));

            return [
                'since_date' => $digits,
                'since' => date('Y-m-d', strtotime("-{$days} days")),
                'since_days' => $days,
            ];
        }

        return $none;
    }

    /**
     * Write a complete index into $stagingDir without touching the live one.
     *
     * @param  array{since_date:?string,since:?string,since_days:?int}  $sinceInfo
     * @param  array{stations?:array<int,string>|string}  $options
     * @return array<string,mixed> Summary destined for the manifest.
     */
    protected function build(FileSystemReader $reader, ?callable $progress, int $chunkSize, string $stagingDir, ?string $tempDir, bool $resume, array $sinceInfo, array $options): array
    {
        $sinceDate = $sinceInfo['since_date'];
        $onlyStations = self::normalizeStationsOption($options['stations'] ?? null);

        // A resumable (or station-scoped) build scans station by station with a
        // checkpoint after each one; the default path keeps the historical
        // single-pass scan so existing readers behave exactly as before.
        $useStationScan = ($resume || $onlyStations !== null)
            && method_exists($reader, 'stationNames')
            && method_exists($reader, 'getFilesGeneratorForStation');

        if ($onlyStations !== null && !$useStationScan) {
            throw new RuntimeException('The stations filter requires a reader with station-scoped scans.');
        }

        /** @var array<string,array<int,array{time:string,observer:string}>> $metadataBySequence */
        $metadataBySequence = [];
        $filesSeen = 0;
        $totalFiles = 0;
        $resumed = false;

        if ($useStationScan) {
            [$sorter, $filesSeen, $totalFiles, $metadataBySequence, $resumed] = $this->scanByStation(
                $reader,
                $progress,
                $chunkSize,
                $stagingDir,
                $tempDir,
                $resume,
                $sinceDate,
                $onlyStations
            );
        } else {
            // Pass 1: stream files, coalescing them into captures. Spill sorted
            // chunks so the whole capture set is never held in memory.
            $sorter = new ExternalSort($chunkSize, true, $tempDir);

            /** @var array<string,array<string,mixed>> $pending */
            $pending = [];

            foreach ($reader->getFilesGenerator(false) as $file) {
                $filesSeen++;

                if ($filesSeen % 5000 === 0 && $progress !== null) {
                    $progress($filesSeen, 'indexing');
                }

                if ($sinceDate !== null && ($file['date'] ?? '') < $sinceDate) {
                    continue;
                }

                $this->ingestFile($file, $pending, $sorter, $metadataBySequence, $chunkSize);
                $totalFiles++;
            }

            if ($pending !== []) {
                $this->flushCaptures($pending, $sorter);
                $pending = [];
            }
        }

        if ($progress !== null) {
            $progress($filesSeen, 'sorting');
        }

        return $this->writeIndex($sorter, $metadataBySequence, $stagingDir, $progress, $filesSeen, $totalFiles, $sinceInfo, $resumed);
    }

    /**
     * Normalise the internal `stations` build option to a sorted name list.
     *
     * @param  mixed  $stations
     * @return array<int,string>|null
     */
    protected static function normalizeStationsOption(mixed $stations): ?array
    {
        if (is_string($stations)) {
            $stations = preg_split('/[\s,]+/', $stations, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (!is_array($stations)) {
            return null;
        }

        $names = [];

        foreach ($stations as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        if ($names === []) {
            return null;
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Scan the tree station by station, checkpointing after each one.
     *
     * Every completed station is flushed to sorted chunks inside the staging
     * directory and recorded in the checkpoint file, so an interrupted build
     * resumed with the same filter skips those stations entirely. The date
     * cutoff is pushed down to the reader, which drops old files from the
     * filename alone, before any stat() or XML parsing.
     *
     * @return array{0:ExternalSort,1:int,2:int,3:array<string,array<int,array{time:string,observer:string}>>,4:bool}
     *     Sorter, files seen, files indexed, observer candidates, resumed flag.
     */
    protected function scanByStation(
        FileSystemReader $reader,
        ?callable $progress,
        int $chunkSize,
        string $stagingDir,
        ?string $tempDir,
        bool $resume,
        ?string $sinceDate,
        ?array $onlyStations
    ): array {
        $baseDir = $reader->getBaseDir();
        $stations = $onlyStations ?? $reader->stationNames();

        $chunksDir = $stagingDir . '/' . self::CHUNKS_DIR;

        if ($resume && !@mkdir($chunksDir, 0775, true) && !is_dir($chunksDir)) {
            throw new RuntimeException("Unable to create checkpoint chunks directory: {$chunksDir}");
        }

        $checkpoint = $resume ? $this->readCheckpoint($stagingDir) : null;
        $checkpointValid = $checkpoint !== null
            && ($checkpoint['base_dir'] ?? null) === $baseDir
            && ($checkpoint['since_date'] ?? null) === $sinceDate;

        if ($resume && $checkpoint !== null && !$checkpointValid) {
            // A checkpoint from another source tree or date filter must never
            // silently skip stations: start over and say so.
            $this->discardStaging($stagingDir);

            if (!@mkdir($chunksDir, 0775, true) && !is_dir($chunksDir)) {
                throw new RuntimeException("Unable to create checkpoint chunks directory: {$chunksDir}");
            }

            if ($progress !== null) {
                $progress(0, 'checkpoint-discarded');
            }

            $checkpoint = null;
        }

        $completed = [];
        $filesSeen = 0;
        $totalFiles = 0;

        $sorter = new ExternalSort($chunkSize, true, $resume ? $chunksDir : $tempDir);

        if ($checkpointValid && $checkpoint !== null) {
            foreach ((array) ($checkpoint['completed_stations'] ?? []) as $name) {
                $completed[(string) $name] = true;
            }

            $filesSeen = (int) ($checkpoint['files_seen'] ?? 0);
            $totalFiles = (int) ($checkpoint['total_files'] ?? 0);

            foreach ((array) ($checkpoint['chunks'] ?? []) as $relative) {
                $sorter->adoptChunk($stagingDir . '/' . ltrim((string) $relative, '/'));
            }

            if ($progress !== null) {
                $progress($filesSeen, 'resumed');
            }
        }

        $resumed = $completed !== [];

        /** @var array<string,array<string,mixed>> $pending */
        $pending = [];

        /** @var array<string,array<int,array{time:string,observer:string}>> $metadataBySequence */
        $metadataBySequence = [];

        foreach ($stations as $station) {
            if (isset($completed[$station])) {
                continue;
            }

            foreach ($reader->getFilesGeneratorForStation($station, false, $sinceDate) as $file) {
                $filesSeen++;

                if ($filesSeen % 5000 === 0 && $progress !== null) {
                    $progress($filesSeen, 'indexing');
                }

                $this->ingestFile($file, $pending, $sorter, $metadataBySequence, $chunkSize);
                $totalFiles++;
            }

            // Completed stations must be durable before checkpointing them:
            // flush the in-memory buffer to chunks still on this pass.
            if ($pending !== []) {
                $this->flushCaptures($pending, $sorter);
                $pending = [];
            }

            $completed[$station] = true;

            if ($resume) {
                $chunks = [];

                foreach ($sorter->chunkFiles() as $absolute) {
                    $chunks[] = ltrim(substr($absolute, strlen($stagingDir) + 1), '/');
                }

                $this->writeCheckpoint($stagingDir, [
                    'version' => 1,
                    'base_dir' => $baseDir,
                    'since_date' => $sinceDate,
                    'completed_stations' => array_keys($completed),
                    'files_seen' => $filesSeen,
                    'total_files' => $totalFiles,
                    'chunks' => $chunks,
                    'updated_at' => date('c'),
                ]);
            }

            if ($progress !== null) {
                $progress($filesSeen, 'indexing');
            }
        }

        return [$sorter, $filesSeen, $totalFiles, $metadataBySequence, $resumed];
    }

    /**
     * Fold one filesystem entry into its capture group.
     *
     * Operator and geolocation facts are read from XML metadata at scan time
     * and stored on the pending capture itself, so they travel with it into
     * the sorted chunks. No per-capture map is ever held in memory, which is
     * what keeps a multi-million-file scan bounded by the chunk size.
     *
     * @param  array<string,array<string,mixed>>  $pending
     * @param  array<string,array<int,array{time:string,observer:string}>>  $metadataBySequence
     */
    protected function ingestFile(array $file, array &$pending, ExternalSort $sorter, array &$metadataBySequence, int $chunkSize): void
    {
        $key = "{$file['station']}_{$file['date']}_{$file['time']}";

        if (!isset($pending[$key])) {
            if (count($pending) >= $chunkSize) {
                $this->flushCaptures($pending, $sorter);
                $pending = [];
            }

            $pending[$key] = $this->newCapture($file);
        }

        $this->attachFile($pending[$key], $file);

        if (($file['type'] ?? null) === 'metadata' && isset($file['path'])) {
            $facts = $this->readMetadataFacts($file['path']);
            $pending[$key]['__facts'] = $this->mergeFacts($pending[$key]['__facts'] ?? null, $facts);

            $observer = $facts['operator']['name'] ?? null;
            $sequence = (string) ($file['sequence'] ?? '');

            if (is_string($observer) && $observer !== '' && $sequence !== '') {
                $associationKey = $this->metadataSequenceKey(
                    (string) $file['station'],
                    (string) $file['date'],
                    $sequence
                );
                $metadataBySequence[$associationKey][] = [
                    'time' => (string) $file['time'],
                    'observer' => $observer,
                ];
            }
        }
    }

    /**
     * Combine metadata facts collected for one capture.
     *
     * Several metadata files can belong to the same capture; the first
     * observed operator and position win, exactly as the previous global map
     * behaved, while a capture with no usable facts stays null.
     *
     * @param  array{operator:?array<string,string>,geolocation:?array<string,?float>}|null  $current
     * @param  array{operator:?array<string,string>,geolocation:?array<string,?float>}  $incoming
     * @return array{operator:?array<string,string>,geolocation:?array<string,?float>}|null
     */
    protected function mergeFacts(?array $current, array $incoming): ?array
    {
        if ($current === null) {
            return ($incoming['operator'] ?? null) === null && ($incoming['geolocation'] ?? null) === null
                ? null
                : $incoming;
        }

        if (($current['operator'] ?? null) === null && ($incoming['operator'] ?? null) !== null) {
            $current['operator'] = $incoming['operator'];
        }

        if (($current['geolocation'] ?? null) === null && ($incoming['geolocation'] ?? null) !== null) {
            $current['geolocation'] = $incoming['geolocation'];
        }

        return $current;
    }

    /**
     * Write the sorted stream, offset table and station blocks into staging.
     *
     * @param  array<string,array<int,array{time:string,observer:string}>>  $metadataBySequence
     * @param  array{since_date:?string,since:?string,since_days:?int}  $sinceInfo
     * @return array<string,mixed> Summary destined for the manifest.
     */
    protected function writeIndex(
        ExternalSort $sorter,
        array $metadataBySequence,
        string $stagingDir,
        ?callable $progress,
        int $filesSeen,
        int $totalFiles,
        array $sinceInfo,
        bool $resumed
    ): array {

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

        // Station line indices, packed as 8-byte big-endian integers per station
        // instead of one PHP array entry per capture, so a multi-million
        // capture set costs ~8 bytes per capture here rather than a zval each.
        /** @var array<string,string> $byStationRecords */
        $byStationRecords = [];

        foreach ($sorter->sorted() as [$sortKey, $capture]) {
            $associationKey = $this->metadataSequenceKey(
                (string) $capture['station'],
                (string) $capture['capture_date'],
                (string) ($capture['_metadata_sequence'] ?? '')
            );
            $capture['observer'] = $this->closestObserver(
                $metadataBySequence[$associationKey] ?? [],
                (string) $capture['capture_time']
            );
            unset($capture['_metadata_sequence']);

            // Operator/geolocation facts travelled with the capture through the
            // sorted chunks (see ingestFile()): they never touch the index payload.
            $facts = $capture['__facts'] ?? null;
            unset($capture['__facts']);

            $line = json_encode($capture, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

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
            if (is_array($facts)) {
                if ($aggregates[$station]['operator'] === null && ($facts['operator'] ?? null) !== null) {
                    $aggregates[$station]['operator'] = $facts['operator'];
                }

                if ($aggregates[$station]['geolocation'] === null && ($facts['geolocation'] ?? null) !== null) {
                    $aggregates[$station]['geolocation'] = $facts['geolocation'];
                }
            }

            $byStationRecords[$station] = ($byStationRecords[$station] ?? '') . pack('J', $lineIndex);

            $totalCaptures++;
            $lineIndex++;
        }

        $this->sync($capturesHandle);
        fclose($capturesHandle);

        $this->sync($linesHandle);
        fclose($linesHandle);

        if ($progress !== null) {
            $progress($filesSeen, 'writing-station-index');
        }

        // Station blocks: write each station's line indices contiguously so a
        // station page can be answered with two seeks.
        $blocks = [];
        $blockHandle = @fopen($this->pathIn($stagingDir, 'by-station.ndjson'), 'wb');

        if ($blockHandle === false) {
            throw new RuntimeException("Unable to open station index output file in {$this->indexDir}");
        }

        $blockOffset = 0;

        ksort($aggregates);

        foreach ($byStationRecords as $station => $packed) {
            $count = (int) (strlen($packed) / self::OFFSET_BYTES);
            $payload = '';

            foreach (unpack('J*', $packed) ?: [] as $i) {
                $payload .= $station . "\t" . $i . "\n";
            }

            fwrite($blockHandle, $payload);
        fflush($blockHandle);
        if (function_exists('fsync')) {
            @fsync($blockHandle);
        }

            $blocks[$station] = [
                'offset' => $blockOffset,
                'count' => $count,
            ];

            $blockOffset += strlen($payload);
        }

        $this->sync($blockHandle);
        fclose($blockHandle);

        unset($byStationRecords);

        $blocksJson = $this->encodeJson($blocks);
        $blocksPath = $this->pathIn($stagingDir, 'station-blocks.json');
        $blocksWritten = file_put_contents($blocksPath, $blocksJson, LOCK_EX);

        if ($blocksWritten === false || $blocksWritten !== strlen($blocksJson)) {
            throw new RuntimeException("Failed to write station blocks to {$blocksPath}");
        }

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
            // Null on a full build; otherwise the date cutoff this index was
            // built with, so readers know the totals cover a recent window.
            'filter' => $sinceInfo['since_date'] !== null
                ? ['since' => $sinceInfo['since'], 'since_days' => $sinceInfo['since_days']]
                : null,
            // True when this build adopted sorted chunks from a checkpoint left
            // by an interrupted run instead of scanning everything itself.
            'resumed' => $resumed,
        ];

        // Write to a temporary file, flush it to disk, then rename it into
        // place. rename() is atomic within a directory, so the final manifest
        // is never observed empty or half-written even if the process is
        // interrupted or the disk runs out of space mid-write.
        // json_encode() returns false on invalid UTF-8 (e.g. a Latin-1 operator
        // name read from XML metadata), and file_put_contents($path, false)
        // writes an empty file while returning 0. Encoding through encodeJson()
        // substitutes invalid bytes and throws instead of silently truncating,
        // and the byte count is checked so a partial write cannot be published.
        $manifestJson = $this->encodeJson($manifest);
        $manifestPath = $this->pathIn($stagingDir, 'manifest.json');
        $tmpManifestPath = $manifestPath . '.tmp';

        $written = file_put_contents($tmpManifestPath, $manifestJson, LOCK_EX);

        if ($written === false || $written !== strlen($manifestJson)) {
            $bytes = $written === false ? 'false' : (string) $written;
            @unlink($tmpManifestPath);
            throw new RuntimeException(
                "Failed to write temporary manifest to {$tmpManifestPath}: wrote {$bytes} of " . strlen($manifestJson) . ' bytes'
            );
        }

        $handle = @fopen($tmpManifestPath, 'r+');
        if ($handle) {
            fflush($handle);
            if (function_exists('fsync')) {
                @fsync($handle);
            }
            fclose($handle);
        }

        if (!rename($tmpManifestPath, $manifestPath)) {
            $error = error_get_last();
            $message = $error['message'] ?? 'Unknown error';
            @unlink($tmpManifestPath);
            throw new RuntimeException("Failed to move temporary manifest to {$manifestPath}. Error: {$message}");
        }

        if ($progress !== null) {
            $progress($filesSeen, 'done');
        }

        return $manifest;

    }

    /**
     * Read the checkpoint of an interrupted resumable build, if any.
     */
    protected function readCheckpoint(string $stagingDir): ?array
    {
        $path = $this->pathIn($stagingDir, self::CHECKPOINT_FILE);

        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Persist a build checkpoint atomically.
     *
     * Written via tmp+rename like the manifest, so a crash mid-write leaves
     * the previous checkpoint intact instead of a half-written JSON file.
     *
     * @param  array<string,mixed>  $checkpoint
     */
    protected function writeCheckpoint(string $stagingDir, array $checkpoint): void
    {
        $path = $this->pathIn($stagingDir, self::CHECKPOINT_FILE);
        $tmp = $path . '.tmp';
        $json = $this->encodeJson($checkpoint);

        $written = file_put_contents($tmp, $json, LOCK_EX);

        if ($written === false || $written !== strlen($json)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to write index checkpoint to {$tmp}");
        }

        $handle = @fopen($tmp, 'r+');

        if ($handle) {
            fflush($handle);

            if (function_exists('fsync')) {
                @fsync($handle);
            }

            fclose($handle);
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to publish index checkpoint to {$path}");
        }
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
     *
     * Recursive: a resumable staging dir also holds the checkpoint file and
     * the sorted-chunk subdirectory, unlike a regular pid-suffixed one.
     */
    protected function discardStaging(string $stagingDir): void
    {
        if (!is_dir($stagingDir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stagingDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
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
     * Encode a value as JSON, substituting invalid UTF-8 instead of failing.
     *
     * Metadata read from XML (notably operator names) is not guaranteed to be
     * UTF-8; JSON_INVALID_UTF8_SUBSTITUTE replaces such bytes with U+FFFD so a
     * single bad byte cannot make json_encode() return false and leave the
     * manifest empty. Any remaining encoding failure is surfaced loudly.
     *
     * @param  mixed  $value
     */
    protected function encodeJson(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            throw new RuntimeException('Failed to encode index data as JSON: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Coerce a string read from metadata into valid UTF-8.
     *
     * XML metadata is not guaranteed to be UTF-8. Values that already are UTF-8
     * are returned unchanged; anything else is treated as Windows-1252 (a
     * superset of ISO-8859-1) and converted, so accented operator names survive
     * instead of becoming a replacement character.
     */
    protected function toUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
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
            'observer' => null,
            '_metadata_sequence' => (string) ($file['sequence'] ?? ''),
            // Operator/geolocation facts read from this capture's XML metadata
            // (see ingestFile()). Carried through the sorted chunks into Pass 2
            // and stripped before the capture is written to the index.
            '__facts' => null,
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

    protected function metadataSequenceKey(string $station, string $date, string $sequence): string
    {
        return implode("\0", [$station, $date, $sequence]);
    }

    /**
     * Resolve metadata from the same station, date and file sequence to the
     * closest capture time, since XML timestamps can differ from image times.
     *
     * @param  array<int,array{time:string,observer:string}>  $candidates
     */
    protected function closestObserver(array $candidates, string $captureTime): ?string
    {
        $toSeconds = static fn (string $time): int =>
            ((int) substr($time, 0, 2) * 3600)
            + ((int) substr($time, 2, 2) * 60)
            + (int) substr($time, 4, 2);

        $captureSeconds = $toSeconds($captureTime);
        $closestDistance = PHP_INT_MAX;
        $closestObserver = null;

        foreach ($candidates as $candidate) {
            $distance = abs($toSeconds($candidate['time']) - $captureSeconds);

            if ($distance < $closestDistance
                || ($distance === $closestDistance && ($closestObserver === null || strcmp($candidate['observer'], $closestObserver) < 0))) {
                $closestDistance = $distance;
                $closestObserver = $candidate['observer'];
            }
        }

        return $closestObserver;
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

        // Metadata is not guaranteed to be UTF-8; a Latin-1 observer name must
        // be converted (not discarded) so operator names stay readable and the
        // manifest keeps encoding cleanly.
        $observer = $this->toUtf8($observer);
        $lid = $this->toUtf8($lid);

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
     * Delete every index artefact, plus any resumable-build staging left behind.
     */
    public function flush(): void
    {
        foreach (self::FILES as $file) {
            $path = $this->path($file);

            if (is_file($path)) {
                @unlink($path);
            }
        }

        // A checkpoint from an interrupted --resume build survives the crash on
        // purpose; flushing the index discards it as well.
        $this->discardStaging($this->stagingDir(true));

        foreach (glob($this->indexDir . '.building-*', GLOB_ONLYDIR) ?: [] as $orphan) {
            $this->discardStaging($orphan);
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
