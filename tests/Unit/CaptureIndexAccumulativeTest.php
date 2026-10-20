<?php

namespace Tests\Unit;

use App\Services\CaptureIndex;
use App\Services\DataProcessor;
use App\Services\FileSystemReader;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Covers accumulative builds: the scan is merged into the index that was
 * already published instead of replacing it.
 */
class CaptureIndexAccumulativeTest extends TestCase
{
    protected string $baseDir;

    protected string $indexDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . '/bramon_accumulative_test_' . getmypid();
        $this->indexDir = $this->baseDir . '_index';

        $this->buildFixtureTree();
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->baseDir);
        $this->deleteTree($this->indexDir);
        $this->deleteTree($this->indexDir . '.building');

        parent::tearDown();
    }

    /**
     * Two stations, two captures each, three files per capture.
     */
    protected function buildFixtureTree(): void
    {
        $captures = [
            ['TLP1', '20250802', '034905'],
            ['TLP1', '20250803', '101500'],
            ['TLP2', '20250802', '040000'],
            ['TLP2', '20250901', '220000'],
        ];

        foreach ($captures as [$station, $date, $time]) {
            $this->writeCapture($station, $date, $time);
        }
    }

    /**
     * Write the three files of a capture.
     */
    protected function writeCapture(string $station, string $date, string $time, bool $withMetadata = true): string
    {
        $dir = $this->baseDir . '/' . $station . '/' . $date;
        @mkdir($dir, 0775, true);

        $stem = "M{$date}_{$time}_{$station}";

        if ($withMetadata) {
            file_put_contents(
                "{$dir}/{$stem}_1.xml",
                '<ufocapture_record observer="Ana" lat="-23.5" lng="-46.6" alt="10"/>'
            );
        }

        file_put_contents("{$dir}/{$stem}_1P.jpg", 'preview');
        file_put_contents("{$dir}/{$stem}_1T.bmp", 'thumb');

        return $dir;
    }

    protected function buildIndex(array $options = [], int $chunkSize = 100): CaptureIndex
    {
        $index = new CaptureIndex($this->indexDir);
        $index->rebuild(new FileSystemReader($this->baseDir, 0), null, $chunkSize, null, $options);

        return $index;
    }

    public function testAccumulativeBuildKeepsTheStoredCapturesAndAddsTheNewOnes(): void
    {
        $index = $this->buildIndex();
        $this->assertSame(4, $index->totalCaptures());

        $this->writeCapture('TLP2', '20250910', '120000');

        $manifest = $this->buildIndex(['accumulative' => true])->manifest();

        $this->assertTrue($manifest['accumulative']);
        $this->assertSame(5, $manifest['total_captures']);
        $this->assertSame(15, $manifest['total_files']);

        // The four captures indexed before are still there.
        $fresh = new CaptureIndex($this->indexDir);
        $this->assertSame(5, $fresh->totalCaptures());

        $dates = array_column($fresh->capturesPage(1, 10)['items'], 'capture_date');
        sort($dates);

        $this->assertSame(['20250802', '20250802', '20250803', '20250901', '20250910'], $dates);
    }

    public function testAccumulativeBuildWithSinceOnlyUpdatesTheWindowAndKeepsTheRest(): void
    {
        $this->buildIndex();

        // A capture inside the window only.
        $this->writeCapture('TLP1', '20250910', '120000');

        $manifest = $this->buildIndex([
            'accumulative' => true,
            'since' => '2025-09-01',
        ])->manifest();

        // Everything is kept: the three captures before the window are carried
        // over and the one inside the window is added.
        $this->assertSame(5, $manifest['total_captures']);
        $this->assertSame(3, $manifest['carried_over']);
        $this->assertSame(['since' => '2025-09-01', 'since_days' => null], $manifest['filter']);

        $dates = array_column((new CaptureIndex($this->indexDir))->capturesPage(1, 10)['items'], 'capture_date');
        sort($dates);

        $this->assertSame(['20250802', '20250802', '20250803', '20250901', '20250910'], $dates);
    }

    public function testAccumulativeBuildUpdatesTheFilesTheScanCovers(): void
    {
        $index = $this->buildIndex();

        $capture = $index->capturesPage(1, 10)['items'][0];
        $this->assertSame('20250901', $capture['capture_date']);

        $dir = $this->baseDir . '/TLP2/20250901';
        unlink("{$dir}/M20250901_220000_TLP2_1P.jpg");

        // The scan covers this capture, so the fresh version replaces it and
        // the deleted file is already gone.
        $manifest = $this->buildIndex(['accumulative' => true])->manifest();

        $this->assertSame(0, $manifest['pruned_files']);
        $this->assertSame(0, $manifest['pruned_captures']);
        $this->assertSame(4, $manifest['total_captures']);
        $this->assertSame(11, $manifest['total_files']);

        $fresh = new CaptureIndex($this->indexDir);
        $updated = $fresh->captureById($capture['id']);

        $this->assertNotNull($updated);
        $this->assertFalse($updated['has_preview']);
        $this->assertSame(2, $updated['file_count']);
        $this->assertCount(2, $updated['files']);
    }

    public function testAccumulativeBuildRemovesTheFilesThatAreGoneOutsideTheWindow(): void
    {
        $index = $this->buildIndex();

        $capture = $this->captureOf($index, 'TLP1', '20250802');

        // A capture the window does not cover loses one of its files.
        $dir = $this->baseDir . '/TLP1/20250802';
        unlink("{$dir}/M20250802_034905_TLP1_1P.jpg");

        $manifest = $this->buildIndex([
            'accumulative' => true,
            'since' => '2025-09-01',
        ])->manifest();

        $this->assertSame(1, $manifest['pruned_files']);
        $this->assertSame(0, $manifest['pruned_captures']);
        $this->assertSame(4, $manifest['total_captures']);
        $this->assertSame(11, $manifest['total_files']);

        $fresh = new CaptureIndex($this->indexDir);
        $updated = $fresh->captureById($capture['id']);

        $this->assertNotNull($updated);
        $this->assertFalse($updated['has_preview']);
        $this->assertSame(2, $updated['file_count']);
        $this->assertCount(2, $updated['files']);
    }

    public function testAccumulativeBuildDropsTheCapturesWhoseFilesAreAllGone(): void
    {
        $index = $this->buildIndex();

        $capture = $this->captureOf($index, 'TLP1', '20250802');

        $dir = $this->baseDir . '/TLP1/20250802';
        foreach (['M20250802_034905_TLP1_1.xml', 'M20250802_034905_TLP1_1P.jpg', 'M20250802_034905_TLP1_1T.bmp'] as $file) {
            unlink("{$dir}/{$file}");
        }

        $manifest = $this->buildIndex([
            'accumulative' => true,
            'since' => '2025-09-01',
        ])->manifest();

        $this->assertSame(3, $manifest['pruned_files']);
        $this->assertSame(1, $manifest['pruned_captures']);
        $this->assertSame(3, $manifest['total_captures']);

        $fresh = new CaptureIndex($this->indexDir);
        $this->assertSame(3, $fresh->totalCaptures());
        $this->assertNull($fresh->captureById($capture['id']));
    }

    public function testAccumulativeBuildReplacesTheKnownCapturesWithTheFreshScan(): void
    {
        $index = $this->buildIndex();

        $capture = $index->capturesPage(1, 10)['items'][0];
        $this->assertFalse($capture['analyzed']);

        // The capture gained an analysis file since the last build.
        file_put_contents(
            $this->baseDir . '/TLP2/20250901/M20250901_220000_TLP2_1A.xml',
            '<ufoanalyzer_record><ua2_objects><ua2_object fs="61" fe="70" sec="0.15" av="5.01" mag="-1.83" class="J8_PPH"/></ua2_objects></ufoanalyzer_record>'
        );

        $manifest = $this->buildIndex(['accumulative' => true])->manifest();

        // The capture is updated, not duplicated.
        $this->assertSame(4, $manifest['total_captures']);
        $this->assertSame(13, $manifest['total_files']);

        $fresh = new CaptureIndex($this->indexDir);
        $updated = $fresh->captureById($capture['id']);

        $this->assertNotNull($updated);
        $this->assertTrue($updated['has_analyze']);
        $this->assertTrue($updated['analyzed']);
        $this->assertSame('J8_PPH', $updated['class']);
    }

    public function testAccumulativeBuildKeepsTheStoredOperatorWhenTheWindowMissesTheStation(): void
    {
        $index = $this->buildIndex();

        $this->assertSame('Ana', $index->stationOperator('TLP1')['name']);

        // The window covers nothing: the station is only carried over.
        $manifest = $this->buildIndex([
            'accumulative' => true,
            'since' => '2030-01-01',
        ])->manifest();

        $this->assertSame(4, $manifest['total_captures']);
        $this->assertSame(4, $manifest['carried_over']);

        $fresh = new CaptureIndex($this->indexDir);
        $this->assertSame('Ana', $fresh->stationOperator('TLP1')['name']);
        $this->assertSame(
            ['latitude' => -23.5, 'longitude' => -46.6, 'altitude' => 10.0],
            $fresh->stationGeolocation('TLP1')
        );
    }

    public function testAccumulativeBuildWithoutAnExistingIndexBehavesLikeAFreshBuild(): void
    {
        $manifest = $this->buildIndex(['accumulative' => true])->manifest();

        $this->assertTrue($manifest['accumulative']);
        $this->assertSame(4, $manifest['total_captures']);
        $this->assertSame(0, $manifest['carried_over']);
    }

    public function testNoPruneKeepsTheCapturesWhoseFilesAreGone(): void
    {
        $this->buildIndex();

        $dir = $this->baseDir . '/TLP2/20250901';
        foreach (['M20250901_220000_TLP2_1.xml', 'M20250901_220000_TLP2_1P.jpg', 'M20250901_220000_TLP2_1T.bmp'] as $file) {
            unlink("{$dir}/{$file}");
        }

        $manifest = $this->buildIndex(['accumulative' => true, 'prune' => false])->manifest();

        $this->assertSame(0, $manifest['pruned_files']);
        $this->assertSame(0, $manifest['pruned_captures']);
        $this->assertSame(4, $manifest['total_captures']);
    }

    public function testAccumulativeBuildReportsTheCapturesThatChangedOutsideTheWindow(): void
    {
        $this->buildIndex();

        // A file of an old capture changes, outside any window.
        $path = $this->baseDir . '/TLP1/20250802/M20250802_034905_TLP1_1P.jpg';
        file_put_contents($path, 'a much longer preview');

        $manifest = $this->buildIndex([
            'accumulative' => true,
            'since' => '2025-09-01',
        ])->manifest();

        $this->assertSame(1, $manifest['stale']);
    }

    public function testResumeDiscardsACheckpointFromAnotherMode(): void
    {
        $index = new CaptureIndex($this->indexDir);

        try {
            $index->rebuild(
                new FileSystemReader($this->baseDir, 0),
                function (int $filesSeen, string $stage): void {
                    if ($stage === 'indexing' && $filesSeen > 0) {
                        throw new \RuntimeException('simulated crash');
                    }
                },
                2,
                null,
                ['resume' => true]
            );
            $this->fail('Expected the simulated crash.');
        } catch (\RuntimeException) {
            // The checkpoint of the plain build now exists.
        }

        $stages = [];

        // Resuming with another mode must not reuse that checkpoint: the
        // chunks were built for a build that replaces the index.
        $index->rebuild(
            new FileSystemReader($this->baseDir, 0),
            function (int $filesSeen, string $stage) use (&$stages): void {
                $stages[] = $stage;
            },
            2,
            null,
            ['resume' => true, 'accumulative' => true]
        );

        $this->assertContains('checkpoint-discarded', $stages);
        $this->assertTrue($index->manifest()['accumulative']);
    }

    /**
     * The indexed capture of a station and a date.
     *
     * @return array<string,mixed>
     */
    protected function captureOf(CaptureIndex $index, string $station, string $date): array
    {
        foreach ($index->capturesPage(1, 50)['items'] as $capture) {
            if ($capture['station'] === $station && $capture['capture_date'] === $date) {
                return $capture;
            }
        }

        $this->fail("No capture of {$station} on {$date} in the index.");
    }

    public function testCommandAccumulativeBuildMergesWithTheExistingIndex(): void
    {
        config([
            'bramon.base_dir' => $this->baseDir,
            'bramon.index_dir' => $this->indexDir,
            'bramon.stations_file' => $this->baseDir . '/stations.json',
        ]);

        // The readers are singletons bound at boot, so drop them to pick up
        // the config above.
        foreach ([
            CaptureIndex::class,
            FileSystemReader::class,
            DataProcessor::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $this->buildIndex();

        $this->writeCapture('TLP2', '20250910', '120000');

        $exit = Artisan::call('bramon:index', [
            '--acumulative' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('accumulative', Artisan::output());
        $this->assertSame(5, (new CaptureIndex($this->indexDir))->totalCaptures());
    }

    public function testCommandRejectsAccumulativeTogetherWithFlush(): void
    {
        $exit = Artisan::call('bramon:index', [
            '--acumulative' => true,
            '--flush' => true,
            '--index-dir' => $this->indexDir,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--acumulative', Artisan::output());
    }

    public function testAccumulativeBuildCanBeResumed(): void
    {
        $index = $this->buildIndex();

        $this->writeCapture('TLP2', '20250910', '120000');

        $resumed = new CaptureIndex($this->indexDir);

        try {
            $resumed->rebuild(
                new FileSystemReader($this->baseDir, 0),
                function (int $filesSeen, string $stage): void {
                    if ($stage === 'indexing' && $filesSeen > 0) {
                        throw new \RuntimeException('simulated crash');
                    }
                },
                2,
                null,
                ['resume' => true, 'accumulative' => true]
            );
            $this->fail('Expected the simulated crash.');
        } catch (\RuntimeException) {
            // The build stopped after the first station.
        }

        $manifest = $resumed->rebuild(
            new FileSystemReader($this->baseDir, 0),
            null,
            2,
            null,
            ['resume' => true, 'accumulative' => true]
        );

        // The checkpoint of the same mode is reused and the merge still runs.
        $this->assertTrue($manifest['resumed']);
        $this->assertTrue($manifest['accumulative']);
        $this->assertSame(5, $manifest['total_captures']);
        $this->assertDirectoryDoesNotExist($this->indexDir . '.building');
    }

    protected function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
