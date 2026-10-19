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

    /** @var array<int,string> Scratch trees built by a test, removed on tearDown. */
    protected array $extraTrees = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . '/bramon_index_test_' . getmypid();
        $this->indexDir = $this->baseDir . '_index';
        $this->extraTrees = [];

        $this->buildFixtureTree();
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->baseDir);
        $this->deleteTree($this->indexDir);

        foreach ($this->extraTrees as $tree) {
            $this->deleteTree($tree);
        }

        $this->extraTrees = [];

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

    public function testIndexReportsIncompatibleManifestAsMissing(): void
    {
        $index = $this->buildIndex();
        file_put_contents($index->manifestPath(), json_encode(['version' => CaptureIndex::VERSION - 1]));

        $this->assertFalse((new CaptureIndex($this->indexDir))->exists());
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

    public function testCaptureReadsAnalysisFromTheAnalyzeFile(): void
    {
        $baseDir = $this->baseDir . '_analysis';
        $indexDir = $this->indexDir . '_analysis';
        $this->extraTrees[] = $baseDir;
        $this->extraTrees[] = $indexDir;
        $dir = $baseDir . '/TLP1/20250802';
        @mkdir($dir, 0775, true);

        file_put_contents($dir . '/M20250802_034905_TLP_1.xml', '<ufocapture_record observer="Ana"/>');
        file_put_contents($dir . '/M20250802_034905_TLP_1A.XML', <<<'XML'
        <?xml version="1.0" encoding="UTF-8" ?>
        <ufoanalyzer_record version="200" clip_name="M20250802_034905_TLP_1" lid="TLP" sid="1">
        	<ua2_objects>
        <ua2_object fs="61" fe="70" sec="0.150150" av="5.019607" mag="-1.838501" class="J8_PPH">
        	</ua2_object>
        	</ua2_objects>
        </ufoanalyzer_record>
        XML);

        $index = new CaptureIndex($indexDir);
        $index->rebuild(new FileSystemReader($baseDir, 0), null, 100);

        $capture = $index->captureById(substr(md5('TLP1_20250802_034905'), 0, 32));

        $this->assertNotNull($capture);
        $this->assertTrue($capture['has_analyze']);
        $this->assertTrue($capture['analyzed']);
        $this->assertSame('J8_PPH', $capture['class']);
        // The index stores the capture as JSON, where a whole number comes
        // back as an int, so the comparison is loose.
        $this->assertEquals(61.0, $capture['fs']);
        $this->assertEquals(70.0, $capture['fe']);
        $this->assertEquals(0.150150, $capture['sec']);
        $this->assertEquals(5.019607, $capture['av']);
        $this->assertEquals(-1.838501, $capture['mag']);

        $types = array_column($capture['files'], 'type');
        $this->assertContains('analyze', $types);
    }

    public function testCaptureWithAnAnalysisWithoutTheExpectedFieldsIsNotAnalysed(): void
    {
        $baseDir = $this->baseDir . '_analysis_empty';
        $indexDir = $this->indexDir . '_analysis_empty';
        $this->extraTrees[] = $baseDir;
        $this->extraTrees[] = $indexDir;
        $dir = $baseDir . '/TLP1/20250802';
        @mkdir($dir, 0775, true);

        file_put_contents($dir . '/M20250802_034905_TLP_1A.XML', <<<'XML'
        <?xml version="1.0" encoding="UTF-8" ?>
        <ufoanalyzer_record version="200">
        	<ua2_objects>
        <ua2_object fps="25" frames="100" dc1="0" dc2="0" />
        	</ua2_objects>
        </ufoanalyzer_record>
        XML);

        $index = new CaptureIndex($indexDir);
        $index->rebuild(new FileSystemReader($baseDir, 0), null, 100);

        $capture = $index->captureById(substr(md5('TLP1_20250802_034905'), 0, 32));

        $this->assertNotNull($capture);
        $this->assertTrue($capture['has_analyze']);
        $this->assertFalse($capture['analyzed']);
        $this->assertNull($capture['class']);
    }

    public function testCaptureWithoutAnalysisKeepsTheAnalysisFieldsEmpty(): void
    {
        $index = $this->buildIndex();

        $capture = $index->captureById(substr(md5('TLP1_20250802_034905'), 0, 32));

        $this->assertNotNull($capture);
        $this->assertFalse($capture['has_analyze']);
        $this->assertFalse($capture['analyzed']);
        $this->assertNull($capture['class']);
        $this->assertNull($capture['fs']);
        $this->assertNull($capture['fe']);
        $this->assertNull($capture['sec']);
        $this->assertNull($capture['av']);
        $this->assertNull($capture['mag']);
    }

    public function testCaptureReadsObserverFromXmlWithMatchingSequenceAndDifferentTime(): void
    {
        $baseDir = $this->baseDir . '_observer';
        $indexDir = $this->indexDir . '_observer';
        $this->extraTrees[] = $baseDir;
        $this->extraTrees[] = $indexDir;
        $dir = $baseDir . '/ELS/20261010';
        @mkdir($dir, 0775, true);

        file_put_contents($dir . '/M20261010_062041_ELS_1T.jpg', 'thumbnail');
        file_put_contents($dir . '/M20261010_075947_ELS_1.xml', '<ufocapture_record observer="Observer Test"/>');

        $index = new CaptureIndex($indexDir);
        $index->rebuild(new FileSystemReader($baseDir, 0), null, 100);

        $captureId = substr(md5('ELS_20261010_062041'), 0, 32);
        $capture = $index->captureById($captureId);

        $this->assertNotNull($capture);
        $this->assertSame('Observer Test', $capture['observer']);
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

        $secondPage = $index->stationCapturesPage('TLP1', 2, 1);
        $this->assertSame(2, $secondPage['total']);
        $this->assertSame('20250802', $secondPage['items'][0]['capture_date']);

        $filteredListPage = $index->capturesPage(2, 1, ['station' => 'TLP1']);
        $this->assertSame(2, $filteredListPage['total']);
        $this->assertSame('20250802', $filteredListPage['items'][0]['capture_date']);

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

    public function testStationAggregatesRecordOperatorFromMetadata(): void
    {
        $index = $this->buildIndex();

        $operator = $index->stationOperator('TLP1');

        // Name and e-mail come from the capture's <ufocapture_record observer=...>
        // tag: the name verbatim, the address derived from it.
        $this->assertNotNull($operator);
        $this->assertSame('Ana', $operator['name']);
        $this->assertSame('ana@bramonmeteor.org', $operator['email']);

        $this->assertSame('Ana', $index->stationOperator('TLP2')['name']);
        $this->assertNull($index->stationOperator('NOPE'));
    }

    public function testManifestIsUsableWhenMetadataContainsInvalidUtf8(): void
    {
        // A Latin-1 observer name ("José") is not valid UTF-8. json_encode()
        // returns false for it, and file_put_contents($path, false) writes an
        // empty file while reporting success, which is exactly how the live
        // manifest ended up at 0 bytes.
        $baseDir = $this->baseDir . '_latin1';
        $indexDir = $this->indexDir . '_latin1';
        $dir = $baseDir . '/TLP5/20250802';
        @mkdir($dir, 0775, true);

        $stem = 'M20250802_034905_TLP5_1';
        file_put_contents("{$dir}/{$stem}.xml", "<ufocapture_record observer=\"Jos\xe9\"/>");
        file_put_contents("{$dir}/{$stem}P.jpg", 'preview');

        $index = new CaptureIndex($indexDir);
        $index->rebuild(new FileSystemReader($baseDir, 0), null, 100);

        $this->assertTrue($index->exists());
        $this->assertGreaterThan(0, (int) @filesize($index->manifestPath()));

        // The manifest must decode to a complete document, not merely be non-empty.
        $decoded = json_decode((string) file_get_contents($index->manifestPath()), true);
        $this->assertIsArray($decoded);
        $this->assertSame(CaptureIndex::VERSION, $decoded['version']);
        $this->assertSame(1, $decoded['total_captures']);

        // The Latin-1 byte is converted, so the operator name stays readable
        // and the station is not dropped.
        $this->assertSame('José', $index->stationOperator('TLP5')['name']);

        $index->flush();
        $this->deleteTree($baseDir);
        $this->deleteTree($indexDir);
    }

    public function testStationWithoutObserverRecordsNoOperator(): void
    {
        // A station whose only capture metadata names nobody: the aggregate still
        // exists, but there is no operator to record.
        $baseDir = $this->baseDir . '_noobserver';
        $indexDir = $this->indexDir . '_noobserver';
        $dir = $baseDir . '/TLP9/20250802';
        @mkdir($dir, 0775, true);

        file_put_contents($dir . '/M20250802_034905_TLP_1.xml', '<ufocapture_record sid="1" cam="IMX291"/>');
        file_put_contents($dir . '/M20250802_034905_TLP_1P.jpg', 'preview');

        $index = new CaptureIndex($indexDir);
        $index->rebuild(new FileSystemReader($baseDir, 0), null, 100);

        $this->assertNull($index->stationOperator('TLP9'));
        $this->assertArrayHasKey('operator', $index->stationAggregate('TLP9'));
        $this->assertSame(1, $index->stationAggregate('TLP9')['count']);

        $index->flush();
        $this->deleteTree($baseDir);
        $this->deleteTree($indexDir);
    }

    public function testStationAggregateKeepsTheNewestUsableFix(): void
    {
        $index = $this->buildGeolocationIndex();

        // TLP8: both captures fixed the position, the newest one wins.
        $this->assertSame(
            ['latitude' => -27.5904, 'longitude' => -48.5925, 'altitude' => 12.0],
            $index->stationGeolocation('TLP8')
        );

        // TLP9: the newest capture never got a fix, so the older one is used
        // instead of serving 0/0 as if it were a location.
        $this->assertSame(
            ['latitude' => -27.5914, 'longitude' => -48.5894, 'altitude' => 45.0],
            $index->stationGeolocation('TLP9')
        );

        // TLP7: a capture without an `alt` attribute still yields coordinates.
        $this->assertSame(
            ['latitude' => -23.5505, 'longitude' => -46.6333, 'altitude' => null],
            $index->stationGeolocation('TLP7')
        );

        // TLP6: no capture ever fixed a position.
        $this->assertNull($index->stationGeolocation('TLP6'));
        $this->assertNull($index->stationGeolocation('NOPE'));

        $index->flush();
    }

    public function testGeolocationRejectsUnusableCoordinates(): void
    {
        $baseDir = $this->baseDir . '_badgeo';
        $indexDir = $this->indexDir . '_badgeo';
        $dir = $baseDir . '/TLP1/20250802';
        @mkdir($dir, 0775, true);

        $stem = 'M20250802_034905_TLP_1';

        // Out of range, non-numeric and unparseable coordinates are all
        // rejected the same way an absent one is.
        file_put_contents("{$dir}/{$stem}.xml", '<ufocapture_record lat="91" lng="0" alt="1"/>');
        file_put_contents("{$dir}/{$stem}1.xml", '<ufocapture_record lat="abc" lng="0" alt="1"/>');
        file_put_contents("{$dir}/{$stem}2.xml", '<ufocapture_record lat="-27.5" alt="1"/>');
        file_put_contents("{$dir}/{$stem}3.xml", '<ufocapture_record observer="Ana"/>');
        file_put_contents("{$dir}/{$stem}_1P.jpg", 'preview');

        $index = new CaptureIndex($indexDir);
        $index->rebuild(new FileSystemReader($baseDir, 0), null, 100);

        $this->assertNull($index->stationGeolocation('TLP1'));
        // The observer is still read from the same tag.
        $this->assertSame('Ana', $index->stationOperator('TLP1')['name']);

        $index->flush();
        $this->deleteTree($baseDir);
        $this->deleteTree($indexDir);
    }

    /**
     * Four stations exercising the position rules: newest fix wins, a newest
     * capture without a fix falls back to the previous one, a missing `alt`,
     * and a station that was never fixed at all.
     */
    protected function buildGeolocationIndex(): CaptureIndex
    {
        $baseDir = $this->baseDir . '_geo';
        $indexDir = $this->indexDir . '_geo';

        $captures = [
            ['TLP8', '20250801', 'lat="-27.5914" lng="-48.5894" alt="45.0"'],
            ['TLP8', '20250803', 'lat="-27.5904" lng="-48.5925" alt="12.0"'],
            ['TLP9', '20250801', 'lat="-27.5914" lng="-48.5894" alt="45.0"'],
            ['TLP9', '20250803', 'lat="0" lng="0" alt="0"'],
            ['TLP7', '20250803', 'lat="-23.5505" lng="-46.6333"'],
            ['TLP6', '20250803', 'sid="1" cam="IMX291"'],
        ];

        foreach ($captures as $i => [$station, $date, $position]) {
            $dir = "{$baseDir}/{$station}/{$date}";
            @mkdir($dir, 0775, true);

            $stem = sprintf('M%s_1000%02d_%s_1', $date, $i, $station);

            file_put_contents("{$dir}/{$stem}.xml", "<ufocapture_record observer=\"Ana\" {$position}/>");
            file_put_contents("{$dir}/{$stem}P.jpg", 'preview');
        }

        $index = new CaptureIndex($indexDir);
        $index->rebuild(new FileSystemReader($baseDir, 0), null, 100);

        // Keep the paths around for tearDown to clean up.
        $this->extraTrees[] = $baseDir;
        $this->extraTrees[] = $indexDir;

        return $index;
    }

    public function testFlushRemovesArtifacts(): void
    {
        $index = $this->buildIndex();
        $this->assertGreaterThan(0, $index->diskUsage());

        $index->flush();

        $this->assertFalse($index->exists());
        $this->assertSame(0, $index->diskUsage());
    }

    public function testRebuildLeavesNoStagingDirectoryBehind(): void
    {
        $this->buildIndex();

        // A leftover staging directory means a build aborted midway and the
        // next rebuild would stage into a second, orphaned tree.
        $this->assertSame([], $this->stagingDirs());
        $this->assertCount(5, $this->indexFiles());
    }

    public function testRebuildReplacesTheIndexInPlace(): void
    {
        $index = $this->buildIndex();
        $this->assertSame(4, $index->totalCaptures());

        // Add a capture to the tree and rebuild over the existing index, which
        // is what the hourly schedule does.
        $dir = $this->baseDir . '/TLP1/20250910';
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/M20250910_120000_TLP1_1.xml', '<ufocapture_record observer="Ana"/>');

        $index->rebuild(new FileSystemReader($this->baseDir, 0), null, 100);

        // A fresh reader has to see the new count, not the previous manifest.
        $fresh = new CaptureIndex($this->indexDir);
        $this->assertSame(5, $fresh->totalCaptures());
        $this->assertCount(5, $this->indexFiles());
        $this->assertSame([], $this->stagingDirs());
    }

    public function testAFailedRebuildLeavesThePreviousIndexIntact(): void
    {
        $index = $this->buildIndex();
        $before = $this->indexFiles();

        // A base directory that vanishes mid-request stands in for any build
        // failure: the previous index must keep serving traffic.
        $reader = new class($this->baseDir, 0) extends FileSystemReader
        {
            public function getFilesGenerator(bool $useCache = false): \Generator
            {
                throw new \RuntimeException('source tree disappeared');
            }
        };

        try {
            $index->rebuild($reader, null, 100);
            $this->fail('Expected the rebuild to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('source tree disappeared', $e->getMessage());
        }

        $this->assertSame($before, $this->indexFiles());
        $this->assertSame([], $this->stagingDirs());

        $fresh = new CaptureIndex($this->indexDir);
        $this->assertTrue($fresh->exists());
        $this->assertSame(4, $fresh->totalCaptures());
    }

    /**
     * Names of the files that make up the live index.
     *
     * @return array<int,string>
     */
    protected function indexFiles(): array
    {
        $files = array_values(array_diff(scandir($this->indexDir) ?: [], ['.', '..']));
        sort($files);

        return $files;
    }

    /**
     * Sibling directories a rebuild would have staged into.
     *
     * @return array<int,string>
     */
    protected function stagingDirs(): array
    {
        $parent = dirname($this->indexDir);
        $prefix = basename($this->indexDir) . '.building-';

        $found = array_values(array_filter(
            scandir($parent) ?: [],
            static fn (string $entry): bool => str_starts_with($entry, $prefix)
        ));

        sort($found);

        return $found;
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
