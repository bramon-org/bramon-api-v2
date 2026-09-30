<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Public\StatsRequest;
use App\Http\Resources\Api\V1\Public\StatsResource;
use App\Services\CaptureIndex;
use App\Services\DataProcessor;
use Illuminate\Http\JsonResponse;

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
            'total_stations' => count($stations),
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
