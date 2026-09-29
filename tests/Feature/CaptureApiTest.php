<?php

namespace Tests\Feature;

use App\Services\DataProcessor;
use App\Services\FileSystemReader;
use App\Services\OperatorReader;
use App\Services\StationReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaptureApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testHealthEndpoint(): void
    {
        $response = $this->getJson('/api/v1/public/health');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'base_dir',
                'base_dir_exists'
            ]);
    }

    public function testStatsEndpoint(): void
    {
        $response = $this->getJson('/api/v1/public/stats');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'total_captures',
                'total_stations',
                'total_operators',
                'total_files',
                'date_range',
                'captures_by_station',
                'captures_by_month',
                'last_updated'
            ]);
    }

    public function testCapturesListEndpoint(): void
    {
        $response = $this->getJson('/api/v1/public/captures');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'total',
                'page',
                'per_page',
                'total_pages',
                'has_next',
                'has_prev'
            ]);
    }

    public function testCapturesSearchEndpoint(): void
    {
        $response = $this->getJson('/api/v1/public/captures/search?q=TLP');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'query',
                'total',
                'results'
            ]);
    }

    public function testStationsListEndpoint(): void
    {
        $response = $this->getJson('/api/v1/public/stations');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'total',
                'page',
                'per_page',
                'total_pages',
                'has_next',
                'has_prev'
            ]);
    }

    public function testOperatorsListEndpoint(): void
    {
        $response = $this->getJson('/api/v1/public/operators');
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'total',
                'page',
                'per_page',
                'total_pages',
                'has_next',
                'has_prev'
            ]);
    }

    public function testCacheInvalidateEndpoint(): void
    {
        $response = $this->postJson('/api/v1/public/cache/invalidate');
        
        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Cache invalidado com sucesso'
            ]);
    }
}