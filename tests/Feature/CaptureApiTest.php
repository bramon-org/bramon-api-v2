<?php

namespace Tests\Feature;

use App\Services\CaptureIndex;
use App\Services\DataProcessor;
use App\Services\FileSystemReader;
use App\Services\OperatorReader;
use App\Services\StationReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end coverage for the public API.
 *
 * A small capture tree and its index are built in setUp so the suite is
 * self-contained instead of depending on a developer's local data directory.
 */
class CaptureApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $baseDir;

    protected string $indexDir;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = getmypid() . '_' . bin2hex(random_bytes(4));
        $this->baseDir = sys_get_temp_dir() . '/bramon_api_test_' . $suffix;
        $this->indexDir = $this->baseDir . '_index';

        $this->buildFixtureTree();

        config([
            'bramon.base_dir' => $this->baseDir,
            'bramon.index_dir' => $this->indexDir,
            'bramon.stations_file' => $this->baseDir . '/stations.json',
            'bramon.operators_file' => $this->baseDir . '/operators.json',
        ]);

        // The readers are singletons bound at boot, so drop them to pick up the
        // config above.
        foreach ([
            CaptureIndex::class,
            FileSystemReader::class,
            OperatorReader::class,
            StationReader::class,
            DataProcessor::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $this->buildIndex();
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->baseDir);
        $this->deleteTree($this->indexDir);

        parent::tearDown();
    }

    protected function buildFixtureTree(): void
    {
        $captures = [
            ['TLP1', '20250802', '034905'],
            ['TLP1', '20250803', '101500'],
            ['TLP2', '20250802', '040000'],
        ];

        foreach ($captures as [$station, $date, $time]) {
            $dir = $this->baseDir . '/' . $station . '/' . $date;
            @mkdir($dir, 0775, true);

            $stem = "M{$date}_{$time}_{$station}";

            file_put_contents("{$dir}/{$stem}_1.xml", '<ufocapture_record observer="Ana Silva"/>');
            file_put_contents("{$dir}/{$stem}_1P.jpg", 'preview');
        }

        file_put_contents($this->baseDir . '/stations.json', json_encode([
            ['name' => 'TLP1', 'city' => 'Sao Paulo', 'state' => 'SP'],
            ['name' => 'TLP2', 'city' => 'Rio', 'state' => 'RJ'],
        ]));

        file_put_contents($this->baseDir . '/operators.json', json_encode([
            [
                'id' => 'op-ana',
                'nome' => 'Ana Silva',
                'email' => 'ana@bramonmeteor.org',
                'estacoes' => ['TLP1'],
            ],
        ]));
    }

    protected function buildIndex(): void
    {
        (new CaptureIndex($this->indexDir))->rebuild(
            new FileSystemReader($this->baseDir, 0),
            null,
            100
        );
    }

    public function testHealthEndpoint(): void
    {
        $response = $this->getJson('/api/v2/public/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'base_dir',
                'base_dir_exists',
                'index' => ['built', 'generated_at', 'total_captures', 'total_stations'],
                'memory' => ['current', 'peak', 'limit'],
            ])
            ->assertJsonPath('status', 'healthy');
    }

    public function testStatsEndpoint(): void
    {
        $response = $this->getJson('/api/v2/public/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total_captures',
                'total_stations',
                'total_operators',
                'total_files',
                'date_range',
                'captures_by_station',
                'captures_by_month',
                'last_updated',
            ])
            ->assertJsonPath('total_captures', 3)
            ->assertJsonPath('total_files', 6)
            ->assertJsonPath('total_stations', 2);
    }

    public function testCapturesListEndpoint(): void
    {
        $response = $this->getJson('/api/v2/public/captures');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'total',
                'page',
                'per_page',
                'total_pages',
                'has_next',
                'has_prev',
            ])
            ->assertJsonPath('total', 3);
    }

    public function testCapturesListIsNewestFirst(): void
    {
        $response = $this->getJson('/api/v2/public/captures?per_page=3');

        $data = $response->json('data');
        $timestamps = array_column($data, 'captured_at');
        $sorted = $timestamps;
        rsort($sorted);

        $this->assertSame($sorted, $timestamps);
    }

    public function testCapturesPaginationIsStable(): void
    {
        $page1 = $this->getJson('/api/v2/public/captures?per_page=2&page=1')->json('data');
        $page2 = $this->getJson('/api/v2/public/captures?per_page=2&page=2')->json('data');

        $ids = array_merge(array_column($page1, 'id'), array_column($page2, 'id'));

        $this->assertCount(3, $ids);
        $this->assertCount(3, array_unique($ids));
    }

    public function testCapturesFilterByStation(): void
    {
        $response = $this->getJson('/api/v2/public/captures?station=TLP1');

        $response->assertStatus(200)->assertJsonPath('total', 2);
    }

    public function testPerPageAboveLimitIsRejectedAs422(): void
    {
        // The request rules cap per_page at 100, so an oversized request is a
        // validation error rather than a server error.
        $this->getJson('/api/v2/public/captures?per_page=5000')
            ->assertStatus(422)
            ->assertJsonStructure(['detail', 'errors' => ['per_page']]);
    }

    public function testCapturesSearchEndpoint(): void
    {
        $response = $this->getJson('/api/v2/public/captures/search?q=TLP1');

        $response->assertStatus(200)
            ->assertJsonStructure(['query', 'total', 'results'])
            ->assertJsonPath('total', 2);
    }

    public function testStationsListEndpoint(): void
    {
        $response = $this->getJson('/api/v2/public/stations');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id', 'name', 'operador', 'city', 'state', 'country', 'active', 'visible',
                        'capture_count', 'first_capture', 'last_capture',
                    ],
                ],
                'total',
                'page',
                'per_page',
                'total_pages',
                'has_next',
                'has_prev',
            ])
            ->assertJsonPath('total', 2);
    }

    public function testStationCarriesTheOperatorObjectInItsOperadorNode(): void
    {
        $response = $this->getJson('/api/v2/public/stations/TLP1');

        $response->assertStatus(200)
            ->assertJsonPath('operador.id', 'op-ana')
            ->assertJsonPath('operador.name', 'Ana Silva')
            ->assertJsonPath('operador.email', 'ana@bramonmeteor.org')
            ->assertJsonPath('operador.role', 'operator')
            ->assertJsonPath('operador.visible', true)
            ->assertJsonPath('operador.active', true)
            ->assertJsonPath('operador.station_count', 1)
            ->assertJsonPath('operador.stations', ['TLP1']);
    }

    public function testStationOperadorNodeIsIdenticalToTheOperatorsEndpoint(): void
    {
        $station = $this->getJson('/api/v2/public/stations/TLP1')->assertStatus(200)->json('operador');
        $operator = $this->getJson('/api/v2/public/operators/op-ana')->assertStatus(200)->json();

        $this->assertSame($operator, $station);
    }

    public function testStationsListResolvesOperadorForEveryStation(): void
    {
        $response = $this->getJson('/api/v2/public/stations');

        $response->assertStatus(200)
            // TLP1 is listed in the operator's explicit 'estacoes' list.
            ->assertJsonPath('data.0.name', 'TLP1')
            ->assertJsonPath('data.0.operador.id', 'op-ana')
            // TLP2 is claimed by nobody, so the node stays null.
            ->assertJsonPath('data.1.name', 'TLP2')
            ->assertJsonPath('data.1.operador', null);
    }

    public function testStationOperadorNodeIsNullWhenNoOperatorIsLinked(): void
    {
        $this->getJson('/api/v2/public/stations/TLP2')
            ->assertStatus(200)
            ->assertJsonPath('operador', null);
    }

    public function testStationShowEndpoint(): void
    {
        $response = $this->getJson('/api/v2/public/stations/TLP1');

        $response->assertStatus(200)
            ->assertJsonPath('name', 'TLP1')
            ->assertJsonPath('city', 'Sao Paulo')
            ->assertJsonPath('capture_count', 2);
    }

    public function testStationShowReturns404ForUnknownStation(): void
    {
        $this->getJson('/api/v2/public/stations/NOPE')->assertStatus(404);
    }

    public function testStationCapturesEndpoint(): void
    {
        $response = $this->getJson('/api/v2/public/stations/TLP1/captures');

        $response->assertStatus(200)->assertJsonPath('total', 2);
    }

    public function testStationStatsEndpoint(): void
    {
        $response = $this->getJson('/api/v2/public/stations/TLP1/stats');

        $response->assertStatus(200)
            ->assertJsonPath('total_captures', 2)
            ->assertJsonPath('first_capture', '2025-08-02 03:49:05')
            ->assertJsonPath('last_capture', '2025-08-03 10:15:00');
    }

    public function testOperatorsListEndpoint(): void
    {
        $response = $this->getJson('/api/v2/public/operators');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'email', 'role', 'visible', 'active', 'station_count', 'stations'],
                ],
                'total',
                'page',
                'per_page',
                'total_pages',
                'has_next',
                'has_prev',
            ])
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.station_count', 1);
    }

    public function testCaptureFileEndpointServesFile(): void
    {
        $captureId = substr(md5('TLP1_20250802_034905'), 0, 32);

        $response = $this->get("/api/v2/public/captures/{$captureId}/files/preview");

        $response->assertStatus(200);
        $response->assertHeader('content-disposition', 'inline; filename="M20250802_034905_TLP1_1P.jpg"');

        $served = $response->baseResponse->getFile()->getPathname();
        $this->assertSame('preview', file_get_contents($served));
    }

    public function testCaptureFileEndpointRejectsUnknownFileType(): void
    {
        $captureId = substr(md5('TLP1_20250802_034905'), 0, 32);

        $this->get("/api/v2/public/captures/{$captureId}/files/bogus")
            ->assertStatus(404);
    }

    public function testUnknownRouteReturns404Not500(): void
    {
        // Guards the API exception handler, which used to report every error
        // as a 500.
        $this->getJson('/api/v2/public/does-not-exist')->assertStatus(404);
    }

    public function testPathTraversalInFileTypeIsRejected(): void
    {
        $captureId = substr(md5('TLP1_20250802_034905'), 0, 32);

        $this->get("/api/v2/public/captures/{$captureId}/files/..%2F..%2F..%2Fetc%2Fpasswd")
            ->assertStatus(404);
    }

    public function testCacheInvalidateEndpoint(): void
    {
        $response = $this->postJson('/api/v2/public/cache/invalidate');

        $response->assertStatus(200)
            ->assertJsonStructure(['message']);
    }

    public function testEndpointsReturn503WhenIndexMissing(): void
    {
        (new CaptureIndex($this->indexDir))->flush();

        foreach ([
            '/api/v2/public/stats',
            '/api/v2/public/stations',
            '/api/v2/public/operators',
            '/api/v2/public/captures',
        ] as $endpoint) {
            $this->getJson($endpoint)->assertStatus(503);
        }
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
