<?php

namespace App\Http\Controllers\Api\V2\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\Public\StatsRequest;
use App\Http\Resources\Api\V2\Public\StatsResource;
use App\Services\CaptureIndex;
use App\Services\DataProcessor;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Aggregate statistics.
 *
 * Everything here is precomputed in the index manifest, so the route no longer
 * walks the captures tree to count files and group captures.
 */
class StatsController extends Controller
{
    public function __construct(
        protected DataProcessor $dataProcessor,
        protected CaptureIndex $index
    ) {}

    #[OA\Get(
        path: '/stats',
        tags: ['Statistics'],
        summary: 'Estatísticas agregadas',
        description: 'Totais de capturas, arquivos, estações e operadores, intervalo de datas coberto, distribuição por estação e por mês, e a data de geração do índice. Todos os valores vêm do manifesto do índice, sem varrer o disco.',
        operationId: 'getStats',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Estatísticas retornadas',
                content: new OA\JsonContent(ref: '#/components/schemas/StatsResponse'),
            ),
            new OA\Response(
                response: 503,
                description: 'Índice de capturas não construído',
                content: new OA\JsonContent(ref: '#/components/schemas/IndexNotReady'),
            ),
        ],
    )]
    public function __invoke(StatsRequest $request): JsonResponse
    {
        if (!$this->index->exists()) {
            return response()->json([
                'detail' => 'Índice de capturas não construído. Execute: php artisan bramon:index',
            ], 503);
        }

        $stations = $this->dataProcessor->stationsFromIndex($this->index);
        $operators = $this->dataProcessor->processOperators($stations);
        $range = $this->index->dateRange();

        $stats = [
            'total_captures' => $this->index->totalCaptures(),
            'total_stations' => $this->index->totalStations(),
            'total_operators' => count($operators),
            'total_files' => $this->index->totalFiles(),
            'date_range' => [
                'earliest' => $range['earliest'],
                'latest' => $range['latest'],
            ],
            'captures_by_station' => $this->index->capturesByStation(),
            'captures_by_month' => $this->index->capturesByMonth(),
            'last_updated' => $this->index->generatedAt() ?? date('c'),
        ];

        return response()->json(new StatsResource($stats));
    }
}
