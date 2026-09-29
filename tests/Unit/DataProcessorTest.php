<?php

namespace Tests\Unit;

use App\Services\DataProcessor;
use App\Services\FileSystemReader;
use Tests\TestCase;

class DataProcessorTest extends TestCase
{
    protected DataProcessor $processor;
    protected FileSystemReader $fileReader;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->fileReader = new FileSystemReader(sys_get_temp_dir() . '/bramon_test', 0);
        // Pass null for readers to use fallback behavior (not load from JSON files)
        $this->processor = new DataProcessor(
            $this->fileReader,
            null,
            null
        );
    }

    public function testGenerateId(): void
    {
        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('generateId');
        $method->setAccessible(true);
        
        $id1 = $method->invoke($this->processor, 'TLP1', '20250802', '034905');
        $id2 = $method->invoke($this->processor, 'TLP1', '20250802', '034905');
        $id3 = $method->invoke($this->processor, 'TLP2', '20250802', '034905');
        
        $this->assertEquals($id1, $id2); // Same input = same ID
        $this->assertNotEquals($id1, $id3); // Different station = different ID
        $this->assertEquals(32, strlen($id1)); // MD5 truncated to 32 chars
    }

    public function testProcessCapturesEmpty(): void
    {
        $captures = $this->processor->processCaptures([]);
        
        $this->assertIsArray($captures);
        $this->assertEmpty($captures);
    }

    public function testProcessStationsFromCaptures(): void
    {
        $captures = [
            [
                'id' => 'abc123',
                'station' => 'TLP1',
                'captured_at' => '2025-08-02 03:49:05',
                'capture_date' => '20250802',
                'capture_time' => '034905',
                'files' => [],
                'file_count' => 3,
                'has_metadata' => true,
                'has_preview' => true,
                'has_thumbnail' => true,
            ],
            [
                'id' => 'def456',
                'station' => 'TLP1',
                'captured_at' => '2025-08-02 04:00:00',
                'capture_date' => '20250802',
                'capture_time' => '040000',
                'files' => [],
                'file_count' => 2,
                'has_metadata' => true,
                'has_preview' => false,
                'has_thumbnail' => true,
            ],
        ];
        
        $stations = $this->processor->processStations($captures);
        
        $this->assertCount(1, $stations);
        $this->assertEquals('TLP1', $stations[0]['name']);
        $this->assertEquals(2, $stations[0]['capture_count']);
        $this->assertEquals('2025-08-02 03:49:05', $stations[0]['first_capture']);
        $this->assertEquals('2025-08-02 04:00:00', $stations[0]['last_capture']);
    }

    public function testProcessOperatorsFallback(): void
    {
        $stations = [
            ['name' => 'TLP1', 'id' => 'station1'],
            ['name' => 'TLP2', 'id' => 'station2'],
        ];
        
        $operators = $this->processor->processOperators($stations);
        
        $this->assertCount(2, $operators);
        $this->assertEquals('Operador TLP1', $operators[0]['name']);
        $this->assertEquals('Operador TLP2', $operators[1]['name']);
    }

    public function testCalculateStats(): void
    {
        $captures = [
            [
                'station' => 'TLP1',
                'captured_at' => '2025-08-02 03:49:05',
                'capture_date' => '20250802',
                'has_metadata' => true,
                'has_preview' => true,
                'has_thumbnail' => true,
            ],
            [
                'station' => 'TLP1',
                'captured_at' => '2025-08-02 04:00:00',
                'capture_date' => '20250802',
                'has_metadata' => true,
                'has_preview' => false,
                'has_thumbnail' => true,
            ],
            [
                'station' => 'TLP2',
                'captured_at' => '2025-08-01 02:00:00',
                'capture_date' => '20250801',
                'has_metadata' => true,
                'has_preview' => true,
                'has_thumbnail' => false,
            ],
        ];
        
        $stations = [
            ['name' => 'TLP1'],
            ['name' => 'TLP2'],
        ];
        
        $operators = [
            ['id' => 'op1'],
        ];
        
        $files = [
            ['size' => 1000],
            ['size' => 2000],
            ['size' => 1500],
        ];
        
        $stats = $this->processor->calculateStats($captures, $stations, $operators, $files);
        
        $this->assertEquals(3, $stats['total_captures']);
        $this->assertEquals(2, $stats['total_stations']);
        $this->assertEquals(1, $stats['total_operators']);
        $this->assertEquals(3, $stats['total_files']);
        $this->assertEquals('2025-08-01 02:00:00', $stats['date_range']['earliest']);
        $this->assertEquals('2025-08-02 04:00:00', $stats['date_range']['latest']);
        $this->assertEquals(2, $stats['captures_by_station']['TLP1']);
        $this->assertEquals(1, $stats['captures_by_station']['TLP2']);
        $this->assertEquals(3, $stats['captures_by_month']['202508']);
    }
}