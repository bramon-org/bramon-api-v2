<?php

namespace App\Console\Commands;

use App\Services\CaptureIndex;
use App\Services\FileSystemReader;
use Illuminate\Console\Command;
use Throwable;

/**
 * Builds the on-disk capture index.
 *
 * The listing endpoints (/stations, /operators, /stats) used to walk the whole
 * captures tree on every request, which exhausted the memory limit and hit the
 * FastCGI read timeout on shared hosting. This command does that work once,
 * in a streaming fashion, and writes the result to disk.
 *
 * Only captures with a valid analysis file (the `A.xml` suffix) are published:
 * the rest is scanned for completeness but never enters the index, so the
 * listing never exposes captures the network has not analysed.
 */
class IndexBuild extends Command
{
    protected $signature = 'bramon:index
                            {--chunk= : Records buffered before spilling a sorted chunk}
                            {--index-dir= : Where to write the index (default: config value)}
                            {--force : Rebuild even if the index already exists}
                            {--flush : Delete the index and exit}
                            {--resume : Resume an interrupted build from its checkpoint instead of starting over}
                            {--fresh : Discard any checkpoint and scan from scratch (use with --resume)}
                            {--since-days= : Only index captures from the last X days (by filename date)}
                            {--since= : Only index captures on/after this date (YYYY-MM-DD or YYYYMMDD)}
                            {--acumulative : Merge the scan into the existing index instead of replacing it}
                            {--no-prune : With --acumulative, keep the stored captures whose files are gone from disk}';

    protected $description = 'Build the on-disk capture index used by the listing endpoints (only captures with a valid analysis are indexed)';

    public function handle(CaptureIndex $index, FileSystemReader $reader): int
    {
        $indexDir = $this->option('index-dir') ?: $index->getIndexDir();

        if ($indexDir !== $index->getIndexDir()) {
            $index = new CaptureIndex($indexDir);
        }

        $accumulative = (bool) $this->option('acumulative');
        $prune = ! (bool) $this->option('no-prune');

        if ($this->option('flush')) {
            if ($accumulative) {
                $this->error('--acumulative cannot be combined with --flush: there is nothing to merge into.');

                return Command::FAILURE;
            }

            $index->flush();
            $this->info('Index removed.');

            return Command::SUCCESS;
        }

        // The captures tree always comes from the configuration: the same path
        // the API reads from, so the index and the listing never disagree.
        $baseDir = config('bramon.base_dir');

        if (!is_dir($baseDir)) {
            $this->error("Base directory does not exist: {$baseDir}");

            return Command::FAILURE;
        }

        if ($index->exists() && !$this->option('force') && !$this->option('resume') && !$accumulative) {
            $this->warn('Index already exists. Use --force to rebuild or --flush to remove it.');
            $this->line("  Captures:  " . number_format($index->totalCaptures()));
            $this->line("  Generated: " . ($index->generatedAt() ?? 'unknown'));

            return Command::SUCCESS;
        }

        $chunkSize = max(1, (int) ($this->option('chunk') ?: config('bramon.index_chunk_size', 20000)));

        // config() returns '' when BRAMON_TEMP_DIR is present but empty (as
        // shipped in .env.example): resolve to the system temp dir so the
        // displayed path and the sorter agree.
        $tempDir = config('bramon.temp_dir', sys_get_temp_dir());

        if (!is_string($tempDir) || $tempDir === '') {
            $tempDir = sys_get_temp_dir();
        }

        $resume = (bool) $this->option('resume');
        $fresh = (bool) $this->option('fresh');
        $sinceDays = $this->option('since-days');
        $since = $this->option('since');

        $reader = new FileSystemReader($baseDir, 0);

        $this->info("Base directory: {$baseDir}");
        $this->info("Index target:    {$index->getIndexDir()}");
        $this->info("Chunk size:      {$chunkSize}");
        $this->info("Temp directory:  {$tempDir}");
        $this->info('Filter:          only captures with a valid analysis (A.XML)');

        if ($resume) {
            $this->info('Mode:            resumable (checkpoints per station)' . ($fresh ? ', fresh scan' : ''));
        }

        if ($accumulative) {
            $this->info(
                'Mode:            accumulative (merging with the existing index'
                . ($prune ? ', pruning the files that are gone' : '')
                . ')'
            );

            if (!$index->exists()) {
                $this->warn('No existing index found: building a fresh one.');
            }
        }

        if ($sinceDays !== null && $sinceDays !== '') {
            $this->info(
                "Date filter:     last {$sinceDays} day(s) only"
                . ($accumulative ? ' (scan window; the rest is carried over)' : '')
            );
        } elseif ($since !== null && $since !== '') {
            $this->info(
                "Date filter:     since {$since}"
                . ($accumulative ? ' (scan window; the rest is carried over)' : '')
            );
        }

        $this->newLine();

        $start = microtime(true);

        try {
            $manifest = $index->rebuild(
                $reader,
                function (int $filesSeen, string $stage): void {
                    if ($stage === 'indexing') {
                        $this->output->write("\r  Scanning... {$filesSeen} files");
                    } elseif ($stage === 'resumed') {
                        $this->newLine();
                        $this->info("  Resumed: checkpoint restored ({$filesSeen} files already indexed)");
                    } elseif ($stage === 'checkpoint-discarded') {
                        $this->newLine();
                        $this->warn('  Previous checkpoint was for another source, filter or version; starting over.');
                    } else {
                        $this->newLine();
                    }
                    // Log stages
                    $this->info("[IndexBuild] Stage: {$stage}, Files seen: {$filesSeen}");
                },
                $chunkSize,
                $tempDir,
                [
                    'resume' => $resume,
                    'fresh' => $fresh,
                    'since_days' => $sinceDays !== null && $sinceDays !== '' ? (int) $sinceDays : null,
                    'since' => $since,
                    'accumulative' => $accumulative,
                    'prune' => $prune,
                ]
            );

            if (empty($manifest)) {
                $this->error('[IndexBuild] Manifest is empty after rebuild.');
            } else {
                $this->info('[IndexBuild] Manifest keys: ' . implode(', ', array_keys($manifest)));
                $this->info('[IndexBuild] Manifest file: OK');
            }
        } catch (Throwable $e) {
            $this->error('Index build failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->output->write("\r");
        $elapsed = microtime(true) - $start;

        $this->info('Index built successfully.');
        $this->newLine();

        $rows = [
            ['Captures', number_format($manifest['total_captures'])],
            ['Files', number_format($manifest['total_files'])],
            ['Stations', number_format($manifest['total_stations'])],
            ['Earliest', $manifest['earliest'] ?? '-'],
            ['Latest', $manifest['latest'] ?? '-'],
        ];

        if (is_array($manifest['filter'] ?? null)) {
            $rows[] = ['Date filter', 'since ' . ($manifest['filter']['since'] ?? '?')];
        }

        if (!empty($manifest['resumed'])) {
            $rows[] = ['Resumed', 'yes (from checkpoint)'];
        }

        if (!empty($manifest['accumulative'])) {
            $carriedOver = (int) ($manifest['carried_over'] ?? 0);
            $scanned = (int) ($manifest['total_captures'] ?? 0) - $carriedOver;

            $rows[] = ['Mode', 'accumulative (merged with the previous index)'];
            $rows[] = ['Scanned', number_format($scanned) . ' captures updated or added'];
            $rows[] = ['Carried over', number_format($carriedOver) . ' captures kept'];

            if ((int) ($manifest['pruned_files'] ?? 0) > 0) {
                $rows[] = ['Removed files', number_format((int) $manifest['pruned_files'])];
            }

            if ((int) ($manifest['pruned_captures'] ?? 0) > 0) {
                $rows[] = ['Removed captures', number_format((int) $manifest['pruned_captures'])];
            }

            if ((int) ($manifest['stale'] ?? 0) > 0) {
                $rows[] = [
                    'Changed outside the window',
                    number_format((int) $manifest['stale']) . ' (reindex with a wider --since to refresh them)',
                ];
            }
        }

        $rows[] = ['Disk usage', $this->humanBytes($index->diskUsage())];
        $rows[] = ['Elapsed', number_format($elapsed, 2) . 's'];
        $rows[] = ['Peak memory', $this->formatBytes(memory_get_peak_usage(true))];

        $this->table(['Metric', 'Value'], $rows);

        // Generate stations.json after building the index
        $this->call('bramon:stations-generate', [
            '--force' => true,
        ]);

        return Command::SUCCESS;
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

    protected function formatBytes(int $bytes): string
    {
        return $this->humanBytes($bytes);
    }
}
