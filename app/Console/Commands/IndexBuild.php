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
 */
class IndexBuild extends Command
{
    protected $signature = 'bramon:index
                            {--base-dir= : Base directory for captures (default: config value)}
                            {--chunk= : Records buffered before spilling a sorted chunk}
                            {--index-dir= : Where to write the index (default: config value)}
                            {--force : Rebuild even if the index already exists}
                            {--flush : Delete the index and exit}
                            {--resume : Resume an interrupted build from its checkpoint instead of starting over}
                            {--fresh : Discard any checkpoint and scan from scratch (use with --resume)}
                            {--since-days= : Only index captures from the last X days (by filename date)}
                            {--since= : Only index captures on/after this date (YYYY-MM-DD or YYYYMMDD)}';

    protected $description = 'Build the on-disk capture index used by the listing endpoints';

    public function handle(CaptureIndex $index, FileSystemReader $reader): int
    {
        $indexDir = $this->option('index-dir') ?: $index->getIndexDir();

        if ($indexDir !== $index->getIndexDir()) {
            $index = new CaptureIndex($indexDir);
        }

        if ($this->option('flush')) {
            $index->flush();
            $this->info('Index removed.');

            return Command::SUCCESS;
        }

        $baseDir = $this->option('base-dir') ?: config('bramon.base_dir');

        if (!is_dir($baseDir)) {
            $this->error("Base directory does not exist: {$baseDir}");

            return Command::FAILURE;
        }

        if ($index->exists() && !$this->option('force') && !$this->option('resume')) {
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

        if ($resume) {
            $this->info('Mode:            resumable (checkpoints per station)' . ($fresh ? ', fresh scan' : ''));
        }

        if ($sinceDays !== null && $sinceDays !== '') {
            $this->info("Date filter:     last {$sinceDays} day(s) only");
        } elseif ($since !== null && $since !== '') {
            $this->info("Date filter:     since {$since}");
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

        $rows[] = ['Disk usage', $this->humanBytes($index->diskUsage())];
        $rows[] = ['Elapsed', number_format($elapsed, 2) . 's'];
        $rows[] = ['Peak memory', $this->formatBytes(memory_get_peak_usage(true))];

        $this->table(['Metric', 'Value'], $rows);

        // Generate stations.json after building the index
        $this->call('bramon:stations-generate', [
            '--base-dir' => $baseDir,
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
