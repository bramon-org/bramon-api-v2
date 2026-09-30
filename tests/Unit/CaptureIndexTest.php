<?php

namespace Tests\Unit;

use App\Services\CaptureIndex;
use App\Services\FileSystemReader;
use Tests\TestCase;

/**
 * Covers the on-disk index that the listing endpoints depend on.
 */
class CaptureIndexTest extends TestCase
{
    protected string $baseDir;

    protected string $indexDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . '/bramon_index_test_' . getmypid();
        $this->indexDir = $this->baseDir . '_index';

        $this->buildFixtureTree();
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->baseDir);
        $this->deleteTree($this->indexDir);

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
            $dir = $this->baseDir . '/' . $station . '/' . $date;
            @mkdir($dir, 0775, true);

            $stem = "M{$date}_{$time}_{$station}";

            file_put_contents("{$dir}/{$stem}_1.xml", '<ufocapture_record observer="Ana"/>');
            file_put_contents("{$dir}/{$stem}_1P.jpg", 'preview');
            file_put_contents("{$dir}/{$stem}_1T.bmp", 'thumb');
        }
    }

    protected function buildIndex(int $chunkSize = 100): CaptureIndex
    {
        $index = new CaptureIndex($this->indexDir);
        $index->rebuild(new FileSystemReader($this->baseDir, 0), null, $chunkSize);

        return $index;
    }

    public function testIndexReportsMissingBeforeBuild(): void
    {
        $index = new CaptureIndex($this->indexDir);

        $this->assertFalse($index->exists());
        $this->assertNull($index->manifest());
        $this->assertSame([], $index->stationAggregates());
    }

    public function testRebuildAggregatesTotals(): void
    {
        $index = $this->buildIndex();

        $this->assertTrue($index->exists());
        $this->assertSame(4, $index->totalCaptures());
        $this->assertSame(12, $index->totalFiles());
        $this->assertSame(2, $index->totalStations());
        $this->assertNotNull($index->generatedAt());

        $range = $index->dateRange();
        $this->assertSame('2025-08-02 03:49:05', $range['earliest']);
        $this->assertSame('2025-09-01 22:00:00', $range['latest']);
    }

    public function testStationAggregates(): void
    {
        $index = $this->buildIndex();

        $aggregate = $index->stationAggregate('TLP1');

        $this->assertNotNull($aggregate);
        $this->assertSame(2, $aggregate['count']);
        $this->assertSame('2025-08-02 03:49:05', $aggregate['first']);
        $this->assertSame('2025-08-03 10:15:00', $aggregate['last']);

        $this->assertNull($index->stationAggregate('NOPE'));
    }

    public function testCapturesPageIsOrderedNewestFirst(): void
    {
        $index = $this->buildIndex();

        $page = $index->capturesPage(1, 10);

        $this->assertCount(4, $page['items']);
        $this->assertSame(4, $page['total']);
        $this->assertSame(1, $page['total_pages']);

        $timestamps = array_column($page['items'], 'captured_at');
        $sorted = $timestamps;
        rsort($sorted);

        $this->assertSame($sorted, $timestamps);
    }

    public function testCapturesPaginationDoesNotOverlap(): void
    {
        $index = $this->buildIndex();

        $first = $index->capturesPage(1, 2);
        $second = $index->capturesPage(2, 2);

        $this->assertCount(2, $first['items']);
        $this->assertCount(2, $second['items']);
        $this->assertSame(2, $first['total_pages']);

        $ids = array_merge(
            array_column($first['items'], 'id'),
            array_column($second['items'], 'id')
        );

        $this->assertCount(4, array_unique($ids));
    }

    public function testCapturesPageBeyondLastPageIsEmpty(): void
    {
        $index = $this->buildIndex();

        $page = $index->capturesPage(99, 10);

        $this->assertSame([], $page['items']);
        $this->assertSame(4, $page['total']);
    }

    public function testCaptureFlagsAreDerivedFromFileSuffixes(): void
    {
        $index = $this->buildIndex();

        $capture = $index->captureById(substr(md5('TLP1_20250802_034905'), 0, 32));

        $this->assertNotNull($capture);
        $this->assertTrue($capture['has_metadata']);
        $this->assertTrue($capture['has_preview']);
        $this->assertTrue($capture['has_thumbnail']);
        $this->assertSame(3, $capture['file_count']);

        $types = array_column($capture['files'], 'type');
        sort($types);
        $this->assertSame(['metadata', 'preview', 'thumbnail'], $types);
    }

    public function testStationFilterReturnsOnlyThatStation(): void
    {
        $index = $this->buildIndex();

        $page = $index->capturesPage(1, 10, ['station' => 'TLP2']);

        $this->assertSame(2, $page['total']);
        foreach ($page['items'] as $capture) {
            $this->assertSame('TLP2', $capture['station']);
        }
    }

    public function testDateFilters(): void
    {
        $index = $this->buildIndex();

        $from = $index->capturesPage(1, 10, ['date_from' => '2025-08-03']);
        $this->assertSame(2, $from['total']);

        $to = $index->capturesPage(1, 10, ['date_to' => '2025-08-02']);
        $this->assertSame(2, $to['total']);
    }

    public function testHasMetadataFilter(): void
    {
        $index = $this->buildIndex();

        $page = $index->capturesPage(1, 10, ['has_metadata' => false]);
        $this->assertSame(0, $page['total']);

        $page = $index->capturesPage(1, 10, ['has_metadata' => true]);
        $this->assertSame(4, $page['total']);
    }

    public function testStationCapturesPageAndStats(): void
    {
        $index = $this->buildIndex();

        $page = $index->stationCapturesPage('TLP1', 1, 1);
        $this->assertCount(1, $page['items']);
        $this->assertSame(2, $page['total']);
        $this->assertSame(2, $page['total_pages']);

        $stats = $index->stationStats('TLP1');
        $this->assertSame(2, $stats['total_captures']);
        $this->assertSame(['202508' => 2], $stats['captures_by_month']);

        $this->assertNull($index->stationStats('NOPE'));
    }

    public function testQuerySearch(): void
    {
        $index = $this->buildIndex();

        $page = $index->capturesPage(1, 10, ['query' => 'TLP2']);
        $this->assertSame(2, $page['total']);

        $page = $index->capturesPage(1, 10, ['query' => 'nothing-matches-this']);
        $this->assertSame(0, $page['total']);
    }

    public function testSmallChunkSizeProducesIdenticalResult(): void
    {
        // Force many spilled chunks to exercise the k-way merge path.
        $index = $this->buildIndex(1);

        $this->assertSame(4, $index->totalCaptures());
        $this->assertSame(12, $index->totalFiles());

        $page = $index->capturesPage(1, 10);
        $timestamps = array_column($page['items'], 'captured_at');
        $sorted = $timestamps;
        rsort($sorted);

        $this->assertSame($sorted, $timestamps);
    }

    public function testRebuildIsDeterministicAcrossChunkSizes(): void
    {
        $reference = $this->buildIndex(100);
        $referenceIds = array_column($reference->capturesPage(1, 10)['items'], 'id');

        $index = new CaptureIndex($this->indexDir);
        $index->rebuild(new FileSystemReader($this->baseDir, 0), null, 1);
        $mergedIds = array_column($index->capturesPage(1, 10)['items'], 'id');

        $this->assertSame($referenceIds, $mergedIds);
    }

    public function testFlushRemovesArtifacts(): void
    {
        $index = $this->buildIndex();
        $this->assertGreaterThan(0, $index->diskUsage());

        $index->flush();

        $this->assertFalse($index->exists());
        $this->assertSame(0, $index->diskUsage());
    }

    public function testNonCaptureFilesAreIgnored(): void
    {
        file_put_contents($this->baseDir . '/readme.txt', 'ignore me');
        file_put_contents($this->baseDir . '/TLP1/operators.json', '[]');

        $index = $this->buildIndex();

        $this->assertSame(4, $index->totalCaptures());
        $this->assertSame(12, $index->totalFiles());
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
