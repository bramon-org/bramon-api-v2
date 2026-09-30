<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\CaptureIndex;
use App\Services\FileSystemReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use OpenApi\Attributes as OA;

/**
 * Health endpoint.
 */
class HealthController extends Controller
{
    public function __construct(
        protected FileSystemReader $fileReader,
        protected CaptureIndex $index
    ) {}

    /**
     * Report API, data directory and index health.
     *
     * Returns 503 when either the data directory or the capture index is
     * unavailable, so a load balancer can take the instance out of rotation.
     */
    #[OA\Get(
        path: '/health',
        tags: ['Health'],
        summary: 'Estado da API',
        description: 'Informa se o diretório de dados existe, se o índice de capturas está construído e quanto de memória o processo consumiu. Retorna 503 quando o índice ou o diretório de dados não estão disponíveis.',
        operationId: 'getHealth',
        responses: [
            new OA\Response(
                response: 200,
                description: 'API saudável',
                content: new OA\JsonContent(ref: '#/components/schemas/HealthResponse'),
            ),
            new OA\Response(
                response: 503,
                description: 'API degradada: diretório de dados ou índice ausente',
                content: new OA\JsonContent(ref: '#/components/schemas/HealthResponse'),
            ),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        $baseDir = Config::get('bramon.base_dir');
        $baseDirExists = is_dir($baseDir);
        $indexExists = $this->index->exists();

        $healthy = $baseDirExists && $indexExists;

        $payload = [
            'status' => $healthy ? 'healthy' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'base_dir' => $baseDir,
            'base_dir_exists' => $baseDirExists,
            'index' => [
                'built' => $indexExists,
                'generated_at' => $indexExists ? $this->index->generatedAt() : null,
                'total_captures' => $indexExists ? $this->index->totalCaptures() : 0,
                'total_stations' => $indexExists ? $this->index->totalStations() : 0,
                'disk_usage' => $indexExists ? $this->index->diskUsage() : 0,
            ],
            'memory' => [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
                'limit' => $this->memoryLimit(),
            ],
        ];

        if (!$indexExists) {
            $payload['hint'] = 'Índice não construído. Execute: php artisan bramon:index';
        }

        return response()->json($payload, $healthy ? 200 : 503);
    }

    /**
     * Parse the configured memory_limit into bytes, or null when unlimited.
     */
    protected function memoryLimit(): ?int
    {
        $limit = ini_get('memory_limit');

        if ($limit === false || $limit === '' || $limit === '-1') {
            return null;
        }

        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
