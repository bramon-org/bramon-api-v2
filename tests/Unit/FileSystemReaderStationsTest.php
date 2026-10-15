<?php

namespace Tests\Unit;

use App\Services\FileSystemReader;
use Tests\TestCase;

/**
 * Covers the station-scoped scans FileSystemReader offers to resumable index
 * builds: deterministic station listing and per-station iteration with a
 * filename-date pre-filter.
 */
class FileSystemReaderStationsTest extends TestCase
{
    protected string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . '/bramon_reader_stations_' . getmypid();

        foreach (['TLP2/20250802', 'TLP1/20250803', 'TLP1/20250901'] as $sub) {
            @mkdir($this->baseDir . '/' . $sub, 0775, true);
        }

        file_put_contents($this->baseDir . '/TLP2/20250802/M20250802_040000_TLP2_1.xml', 'x');
        file_put_contents($this->baseDir . '/TLP1/20250803/M20250803_101500_TLP1_1.xml', 'x');
        file_put_contents($this->baseDir . '/TLP1/20250901/M20250901_220000_TLP1_1.xml', 'x');
        file_put_contents($this->baseDir . '/operators.json', '[]');
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->baseDir);

        parent::tearDown();
    }

    public function testStationNamesAreSortedAndSkipFiles(): void
    {
        $reader = new FileSystemReader($this->baseDir, 0);

        $this->assertSame(['TLP1', 'TLP2'], $reader->stationNames());
    }

    public function testStationScanYieldsOnlyThatStation(): void
    {
        $reader = new FileSystemReader($this->baseDir, 0);

        $files = iterator_to_array($reader->getFilesGeneratorForStation('TLP1', false), false);

        $this->assertCount(2, $files);

        foreach ($files as $file) {
            $this->assertSame('TLP1', $file['station']);
        }

        // rel_path stays rooted at the base dir, like the full scan.
        $this->assertStringStartsWith('TLP1/', $files[0]['rel_path']);
    }

    public function testStationScanWithSinceDateDropsOldFiles(): void
    {
        $reader = new FileSystemReader($this->baseDir, 0);

        $files = iterator_to_array(
            $reader->getFilesGeneratorForStation('TLP1', false, '20250901'),
            false
        );

        $this->assertCount(1, $files);
        $this->assertSame('20250901', $files[0]['date']);
    }

    public function testStationScanOfMissingStationIsEmpty(): void
    {
        $reader = new FileSystemReader($this->baseDir, 0);

        $files = iterator_to_array(
            $reader->getFilesGeneratorForStation('NOPE', false),
            false
        );

        $this->assertSame([], $files);
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
