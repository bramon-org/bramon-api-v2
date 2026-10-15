<?php

namespace Tests\Unit;

use App\Services\CaptureIndex;
use App\Services\FileSystemReader;
use Tests\TestCase;

/**
 * Covers resumable (checkpointed) builds and date-filtered builds of the
 * on-disk capture index: the two tools `bramon:index` offers for very large
 * captures trees.
 */
class CaptureIndexResumeTest extends TestCase
{
    protected string $baseDir;

    protected string $indexDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . '/bramon_resume_test_' . getmypid();
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
     * Two stations, two captures each, three files per capture, on distinct
     * dates so the date filter can split them.
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
            $dir = $this->baseDir . '/' . $station . '/' . $date;
            @mkdir($dir, 0775, true);

            $stem = "M{$date}_{$time}_{$station}";

            file_put_contents("{$dir}/{$stem}_1.xml", '<ufocapture_record observer="Ana" lat="-23.5" lng="-46.6" alt="10"/>');
            file_put_contents("{$dir}/{$stem}_1P.jpg", 'preview');
            file_put_contents("{$dir}/{$stem}_1T.bmp", 'thumb');
        }
    }

    public function testResumableBuildMatchesFreshBuild(): void
    {
        $reference = new CaptureIndex($this->indexDir);
        $reference->rebuild(new FileSystemReader($this->baseDir, 0), null, 2);
        $referenceIds = array_column($reference->capturesPage(1, 10)['items'], 'id');
        $reference->flush();

        $index = new CaptureIndex($this->indexDir);

        $crashed = false;

        try {
            // Crash right after the first station is checkpointed: the
            // progress hook runs once per completed station.
            $index->rebuild(
                new FileSystemReader($this->baseDir, 0),
                function (int $filesSeen, string $stage) use (&$crashed): void {
                    if ($stage === 'indexing' && $filesSeen > 0 && !$crashed) {
                        $crashed = true;

                        throw new \RuntimeException('simulated crash');
                    }
                },
                2,
                null,
                ['resume' => true]
            );
            $this->fail('Expected the simulated crash.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated crash', $e->getMessage());
        }

        $this->assertTrue($crashed);

        // The interrupted build must have left its checkpoint behind.
        $this->assertFileExists($this->indexDir . '.building/' . CaptureIndex::CHECKPOINT_FILE);

        // Resume and finish.
        $manifest = $index->rebuild(
            new FileSystemReader($this->baseDir, 0),
            null,
            2,
            null,
            ['resume' => true]
        );

        $this->assertTrue($manifest['resumed']);
        $this->assertSame(4, $index->totalCaptures());
        $this->assertSame(12, $index->totalFiles());
        $this->assertSame(2, $index->totalStations());

        $this->assertSame($referenceIds, array_column($index->capturesPage(1, 10)['items'], 'id'));

        // Operator/geolocation facts survived the chunk round-trip.
        $this->assertSame('Ana', $index->stationOperator('TLP1')['name']);
        $this->assertSame(
            ['latitude' => -23.5, 'longitude' => -46.6, 'altitude' => 10.0],
            $index->stationGeolocation('TLP1')
        );

        // Scan-time internals never leak into the stored payload.
        $capture = $index->captureById($referenceIds[0]);
        $this->assertNotNull($capture);
        $this->assertArrayNotHasKey('__facts', $capture);
        $this->assertArrayNotHasKey('_metadata_sequence', $capture);

        // A finished build leaves no staging directory behind.
        $this->assertDirectoryDoesNotExist($this->indexDir . '.building');
    }

    public function testFreshResumeBuildWithoutCheckpointIsNotMarkedResumed(): void
    {
        $index = new CaptureIndex($this->indexDir);
        $manifest = $index->rebuild(
            new FileSystemReader($this->baseDir, 0),
            null,
            100,
            null,
            ['resume' => true]
        );

        $this->assertFalse($manifest['resumed']);
        $this->assertNull($manifest['filter']);
        $this->assertSame(4, $index->totalCaptures());
        $this->assertDirectoryDoesNotExist($this->indexDir . '.building');
    }

    public function testSinceDateFilterIndexesOnlyRecentCaptures(): void
    {
        $index = new CaptureIndex($this->indexDir);
        $manifest = $index->rebuild(
            new FileSystemReader($this->baseDir, 0),
            null,
            100,
            null,
            ['since' => '2025-09-01']
        );

        $this->assertSame(1, $index->totalCaptures());
        $this->assertSame(3, $index->totalFiles());
        $this->assertSame(['since' => '2025-09-01', 'since_days' => null], $manifest['filter']);

        $page = $index->capturesPage(1, 10);
        $this->assertSame('20250901', $page['items'][0]['capture_date']);
    }

    public function testSinceDateFilterWorksWithResume(): void
    {
        $index = new CaptureIndex($this->indexDir);
        $manifest = $index->rebuild(
            new FileSystemReader($this->baseDir, 0),
            null,
            2,
            null,
            ['resume' => true, 'since' => '20250803']
        );

        // TLP1@0803 and TLP2@0901 survive; both older captures are dropped.
        $this->assertSame(2, $index->totalCaptures());
        $this->assertSame(6, $index->totalFiles());
        $this->assertSame(['since' => '2025-08-03', 'since_days' => null], $manifest['filter']);
    }

    public function testSinceDaysZeroBuildsAnEmptyIndex(): void
    {
        $index = new CaptureIndex($this->indexDir);
        $manifest = $index->rebuild(
            new FileSystemReader($this->baseDir, 0),
            null,
            100,
            null,
            ['since_days' => 0]
        );

        $this->assertSame(0, $index->totalCaptures());
        $this->assertSame(date('Y-m-d'), $manifest['filter']['since']);
        $this->assertSame(0, $manifest['filter']['since_days']);
    }

    public function testSinceDaysLargeWindowIndexesEverything(): void
    {
        $index = new CaptureIndex($this->indexDir);
        $manifest = $index->rebuild(
            new FileSystemReader($this->baseDir, 0),
            null,
            100,
            null,
            ['since_days' => 3650]
        );

        $this->assertSame(4, $index->totalCaptures());
        $this->assertSame(3650, $manifest['filter']['since_days']);
    }

    public function testInvalidSinceDateThrows(): void
    {
        $index = new CaptureIndex($this->indexDir);

        $this->expectException(\RuntimeException::class);

        $index->rebuild(
            new FileSystemReader($this->baseDir, 0),
            null,
            100,
            null,
            ['since' => 'not-a-date']
        );
    }

    public function testCheckpointIsDiscardedWhenFilterChanges(): void
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
            // Checkpoint for the unfiltered build now exists.
        }

        $stages = [];

        // Resuming with a different filter must not reuse that checkpoint.
        $index->rebuild(
            new FileSystemReader($this->baseDir, 0),
            function (int $filesSeen, string $stage) use (&$stages): void {
                $stages[] = $stage;
            },
            2,
            null,
            ['resume' => true, 'since' => '2025-09-01']
        );

        $this->assertContains('checkpoint-discarded', $stages);
        $this->assertSame(1, $index->totalCaptures());
    }

    protected function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
