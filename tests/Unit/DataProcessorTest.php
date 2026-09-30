<?php

namespace Tests\Unit;

use App\Services\CaptureIndex;
use App\Services\DataProcessor;
use App\Services\FileSystemReader;
use App\Services\OperatorReader;
use App\Services\StationReader;
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

    public function testAttachOperatorsResolvesFromTheStationName(): void
    {
        $processor = $this->processorWithOperators([
            [
                'id' => 'op-ana',
                'nome' => 'Ana Silva',
                'email' => 'ana@bramonmeteor.org',
                'estacoes' => ['TLP1'],
            ],
            [
                'id' => 'op-bruno',
                'nome' => 'Bruno Dias',
                'email' => 'bruno@bramonmeteor.org',
                'estacao' => 'TLP2',
            ],
        ]);

        $stations = [
            ['name' => 'TLP1', 'operador' => null],
            ['name' => 'TLP2', 'operador' => null],
        ];

        $processor->attachOperators($stations);

        // Explicit 'estacoes' list.
        $this->assertSame('op-ana', $stations[0]['operador']['id']);
        $this->assertSame('Ana Silva', $stations[0]['operador']['name']);

        // 'estacao' prefix.
        $this->assertSame('op-bruno', $stations[1]['operador']['id']);
        $this->assertSame('Bruno Dias', $stations[1]['operador']['name']);
    }

    public function testAttachedOperatorCarriesTheFullOperatorPayload(): void
    {
        $processor = $this->processorWithOperators([
            [
                'id' => 'op-ana',
                'nome' => 'Ana Silva',
                'email' => 'ana@bramonmeteor.org',
                'estacoes' => ['TLP1', 'TLP2'],
                'visivel' => false,
                'ativo' => true,
            ],
        ]);

        $stationList = [
            ['name' => 'TLP1'],
            ['name' => 'TLP2'],
        ];

        $stations = [
            ['name' => 'TLP1', 'operador' => null],
            ['name' => 'TLP2', 'operador' => null],
        ];

        $processor->attachOperators($stations);

        // Byte-for-byte what /operators returns for the same operator.
        $this->assertSame($processor->processOperators($stationList)[0], $stations[0]['operador']);
        $this->assertSame($processor->processOperators($stationList)[0], $stations[1]['operador']);
    }

    public function testAttachOperatorsFallsBackToTheDeclaredOperatorId(): void
    {
        $processor = $this->processorWithOperators([
            ['id' => 'op-ana', 'nome' => 'Ana Silva', 'estacao' => 'STA'],
        ]);

        $stations = [
            ['name' => 'TLP1', 'operador' => null],
        ];

        $processor->attachOperators($stations, [
            'TLP1' => ['name' => 'TLP1', 'operador' => 'op-ana'],
        ]);

        $this->assertSame('op-ana', $stations[0]['operador']['id']);
    }

    public function testAttachOperatorsLeavesUnownedStationsNull(): void
    {
        $processor = $this->processorWithOperators([
            ['id' => 'op-ana', 'nome' => 'Ana Silva', 'estacao' => 'STA'],
        ]);

        $stations = [
            ['name' => 'TLP1', 'operador' => null],
        ];

        $processor->attachOperators($stations, [
            // Dangling id from an older dataset: no operator matches it.
            'TLP1' => ['name' => 'TLP1', 'operador' => 'ffffffff-eeee-dddd-cccc-bbbbbbbbbbbb'],
        ]);

        $this->assertNull($stations[0]['operador']);
    }

    public function testAttachOperatorsSynthesisesOneOperatorPerStationWithoutOperatorsFile(): void
    {
        $stations = [
            ['name' => 'TLP1', 'operador' => null],
        ];

        $this->processor->attachOperators($stations);

        $this->assertSame('Operador TLP1', $stations[0]['operador']['name']);
        $this->assertSame(['TLP1'], $stations[0]['operador']['stations']);
    }

    public function testFallbackOperatorUsesTheNameAndEmailRecordedByTheIndex(): void
    {
        $processor = $this->processorWithIndexedOperator('THIAGO_PAES', 'thiago.paes@bramonmeteor.org');

        $stations = [
            ['name' => 'TLP1', 'operador' => null],
        ];

        $processor->attachOperators($stations);

        // The captures of TLP1 name the observer, so that is what is served —
        // not the generic "Operador TLP1" pair.
        $this->assertSame('THIAGO_PAES', $stations[0]['operador']['name']);
        $this->assertSame('thiago.paes@bramonmeteor.org', $stations[0]['operador']['email']);
        $this->assertSame(['TLP1'], $stations[0]['operador']['stations']);

        $this->assertSame('THIAGO_PAES', $processor->processOperators($stations)[0]['name']);
    }

    public function testFallbackOperatorFallsBackToTheStationWhenTheIndexKnowsNobody(): void
    {
        $processor = $this->processorWithIndexedOperator(null, null);

        $stations = [
            ['name' => 'TLP1', 'operador' => null],
        ];

        $processor->attachOperators($stations);

        $this->assertSame('Operador TLP1', $stations[0]['operador']['name']);
        $this->assertSame('tlp1@bramonmeteor.org', $stations[0]['operador']['email']);
    }

    public function testStationGeolocationComesFromTheIndexedFix(): void
    {
        [$processor, $index] = $this->indexedStations([
            'TLP1' => $this->indexedAggregate(geolocation: [
                'latitude' => -27.5904,
                'longitude' => -48.5925,
                'altitude' => 12.0,
            ]),
        ], [
            ['name' => 'TLP1', 'latitude' => -23.5505, 'longitude' => -46.6333, '_metadata' => ['altitude' => '760']],
        ]);

        $stations = $processor->stationsFromIndex($index);

        $this->assertSame(-27.5904, $stations[0]['latitude']);
        $this->assertSame(-48.5925, $stations[0]['longitude']);
        $this->assertSame(12.0, $stations[0]['altitude']);

        // Curated-only fields have no capture source, so they are untouched.
        $this->assertSame(0.0, $stations[0]['azimuth']);
        $this->assertSame(0.0, $stations[0]['elevation']);
    }

    public function testStationFallsBackToCuratedGeolocationWithoutAnIndexedFix(): void
    {
        [$processor, $index] = $this->indexedStations([
            'TLP1' => $this->indexedAggregate(),
            'TLP2' => $this->indexedAggregate(),
        ], [
            [
                'name' => 'TLP1',
                'latitude' => '-27.5904',
                'longitude' => '-48.5925',
                '_metadata' => ['altitude' => '12.0'],
            ],
            ['name' => 'TLP2', 'latitude' => -23.5505, 'longitude' => -46.6333],
        ]);

        $stations = $processor->stationsFromIndex($index);

        // No capture fixed a position: the curated station entry is used, and
        // the altitude it curates under _metadata is surfaced as `altitude`.
        $this->assertSame(-27.5904, $stations[0]['latitude']);
        $this->assertSame(12.0, $stations[0]['altitude']);

        // Nothing curated either: coordinates stay at zero and altitude is null
        // rather than pretending to be a measurement.
        $this->assertSame(-23.5505, $stations[1]['latitude']);
        $this->assertNull($stations[1]['altitude']);
    }

    public function testOperatorGeolocationComesFromTheFirstLinkedStation(): void
    {
        [$processor, $index] = $this->indexedStations([
            'TLP1' => $this->indexedAggregate(geolocation: [
                'latitude' => -27.5904,
                'longitude' => -48.5925,
                'altitude' => 12.0,
            ]),
            'TLP2' => $this->indexedAggregate(geolocation: [
                'latitude' => -23.5505,
                'longitude' => -46.6333,
                'altitude' => 760.0,
            ]),
            'TLP3' => $this->indexedAggregate(),
        ], null, [
            ['id' => 'op-ana', 'nome' => 'Ana', 'email' => 'ana@bramonmeteor.org', 'estacoes' => ['TLP2', 'TLP1']],
            ['id' => 'op-bruno', 'nome' => 'Bruno', 'email' => 'bruno@bramonmeteor.org', 'estacoes' => ['TLP3', 'TLP2']],
        ]);

        $stations = $processor->stationsFromIndex($index);
        $operators = $processor->processOperators($stations);

        // The declared order wins: TLP2 is the first station of this operator,
        // so its position is served and TLP1's is ignored.
        $this->assertSame(-23.5505, $operators[0]['latitude']);
        $this->assertSame(-46.6333, $operators[0]['longitude']);
        $this->assertSame(760.0, $operators[0]['altitude']);

        // First station never fixed a position: the fields are present and null.
        $this->assertNull($operators[1]['latitude']);
        $this->assertNull($operators[1]['longitude']);
        $this->assertNull($operators[1]['altitude']);
    }

    public function testAttachedOperatorCarriesTheSameGeolocationAsTheOperatorsEndpoint(): void
    {
        [$processor, $index] = $this->indexedStations([
            'TLP1' => $this->indexedAggregate(geolocation: [
                'latitude' => -27.5904,
                'longitude' => -48.5925,
                'altitude' => 12.0,
            ]),
        ], null, [
            ['id' => 'op-ana', 'nome' => 'Ana', 'email' => 'ana@bramonmeteor.org', 'estacoes' => ['TLP1']],
        ]);

        $stations = $processor->stationsFromIndex($index);
        $listed = $processor->processOperators($stations);

        $this->assertSame($listed[0]['latitude'], $stations[0]['operador']['latitude']);
        $this->assertSame($listed[0]['longitude'], $stations[0]['operador']['longitude']);
        $this->assertSame($listed[0]['altitude'], $stations[0]['operador']['altitude']);
    }

    public function testFallbackOperatorCarriesItsOwnStationGeolocation(): void
    {
        [$processor, $index] = $this->indexedStations([
            'TLP1' => $this->indexedAggregate(geolocation: [
                'latitude' => -27.5904,
                'longitude' => -48.5925,
                'altitude' => 12.0,
            ]),
        ]);

        $stations = $processor->stationsFromIndex($index);
        $operators = $processor->processOperators($stations);

        $this->assertSame('Operador TLP1', $operators[0]['name']);
        $this->assertSame(-27.5904, $operators[0]['latitude']);
        $this->assertSame(12.0, $operators[0]['altitude']);
    }

    /**
     * DataProcessor wired to a CaptureIndex whose manifest already recorded the
     * operator of TLP1, as `bramon:index` does from the capture metadata.
     */
    protected function processorWithIndexedOperator(?string $name, ?string $email): DataProcessor
    {
        $operator = $name === null ? null : ['id' => 'TLP', 'name' => $name, 'email' => $email];

        return $this->indexedStations([
            'TLP1' => $this->indexedAggregate($operator),
        ])[0];
    }

    /**
     * A DataProcessor and the CaptureIndex behind it, whose manifest already
     * recorded the given station aggregates, as `bramon:index` does from the
     * capture metadata.
     *
     * @param  array<string,array<string,mixed>>  $aggregates
     * @param  array<int,array<string,mixed>>|null  $stationsFile
     * @param  array<int,array<string,mixed>>|null  $operators
     * @return array{0:DataProcessor,1:CaptureIndex}
     */
    protected function indexedStations(array $aggregates, ?array $stationsFile = null, ?array $operators = null): array
    {
        $dir = sys_get_temp_dir() . '/bramon_index_ops_' . getmypid() . '_' . uniqid();
        @mkdir($dir, 0775, true);

        file_put_contents($dir . '/manifest.json', json_encode([
            'version' => CaptureIndex::VERSION,
            'station_aggregates' => $aggregates,
        ]));

        $index = new CaptureIndex($dir);

        $stationReader = null;

        if ($stationsFile !== null) {
            $file = tempnam(sys_get_temp_dir(), 'bramon_stations_') . '.json';
            file_put_contents($file, json_encode($stationsFile));
            $stationReader = new StationReader($file);
        }

        $operatorReader = null;

        if ($operators !== null) {
            $file = tempnam(sys_get_temp_dir(), 'bramon_ops_') . '.json';
            file_put_contents($file, json_encode($operators));
            $operatorReader = new OperatorReader($file);
        }

        return [
            new DataProcessor($this->fileReader, $operatorReader, $stationReader, $index),
            $index,
        ];
    }

    /**
     * A station aggregate as the rebuild writes it.
     *
     * @param  array<string,mixed>|null  $operator
     * @param  array<string,mixed>|null  $geolocation
     * @return array<string,mixed>
     */
    protected function indexedAggregate(?array $operator = null, ?array $geolocation = null): array
    {
        return [
            'count' => 1,
            'first' => '2025-08-02 03:49:05',
            'last' => '2025-08-02 03:49:05',
            'operator' => $operator,
            'geolocation' => $geolocation,
        ];
    }

    /**
     * DataProcessor wired to an OperatorReader serving the given operators.
     *
     * @param  array<int,array<string,mixed>>  $operators
     */
    protected function processorWithOperators(array $operators): DataProcessor
    {
        $file = tempnam(sys_get_temp_dir(), 'bramon_ops_') . '.json';
        file_put_contents($file, json_encode($operators));

        return new DataProcessor($this->fileReader, new OperatorReader($file), null);
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