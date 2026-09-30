<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Public\StationListRequest;
use App\Http\Requests\Api\V1\Public\StationCapturesRequest;
use App\Http\Resources\Api\V1\Public\CaptureResource;
use App\Http\Resources\Api\V1\Public\PaginatedResource;
use App\Http\Resources\Api\V1\Public\StationResource;
use App\Services\CaptureIndex;
use App\Services\DataProcessor;
use Illuminate\Http\JsonResponse;

/**
 * Station endpoints.
 *
 * All responses are served from the prebuilt CaptureIndex. Station listing and
 * lookups previously materialised every file in the captures tree, which
 * exhausted the PHP memory limit; now request memory scales with the page size
 * and the number of stations instead.
 */
class StationController extends Controller
{
    public function __construct(
        protected DataProcessor $dataProcessor,
        protected CaptureIndex $index
    ) {}

    /**
     * List stations with pagination.
     */
    public function index(StationListRequest $request): JsonResponse
    {
        $page = max(1, $request->integer('page', 1));
        $perPage = $this->perPage($request->integer('per_page', 15));
        $activeOnly = $request->boolean('active_only', false);

        if (!$this->index->exists()) {
            return $this->indexMissingResponse();
        }

        // Station count is bounded (hundreds), so building the list here is
        // cheap; only the capture scan used to be unbounded.
        $stations = $this->dataProcessor->stationsFromIndex($this->index);

        if ($activeOnly) {
            $stations = array_filter($stations, fn($s) => $s['active']);
        }

        $stations = array_values($stations);

        $total = count($stations);
        $totalPages = (int) ceil($total / $perPage);
        $start = ($page - 1) * $perPage;

        $resource = new PaginatedResource(
            StationResource::collection(array_slice($stations, $start, $perPage))->resolve(),
            $total,
            $page,
            $perPage,
            $totalPages,
            $page < $totalPages,
            $page > 1
        );

        return response()->json($resource);
    }

    /**
     * Get a specific station by name.
     */
    public function show(string $stationName): JsonResponse
    {
        if (!$this->index->exists()) {
            return $this->indexMissingResponse();
        }

        $aggregate = $this->index->stationAggregate($stationName);

        if ($aggregate === null) {
            return response()->json(['detail' => 'Estação não encontrada'], 404);
        }

        $stations = $this->dataProcessor->stationsFromIndex($this->index);

        foreach ($stations as $station) {
            if ($station['name'] === $stationName) {
                return response()->json(new StationResource($station));
            }
        }

        return response()->json(['detail' => 'Estação não encontrada'], 404);
    }

    /**
     * Get captures for a specific station.
     */
    public function captures(string $stationName, StationCapturesRequest $request): JsonResponse
    {
        if (!$this->index->exists()) {
            return $this->indexMissingResponse();
        }

        if ($this->index->stationAggregate($stationName) === null) {
            return response()->json(['detail' => 'Estação não encontrada'], 404);
        }

        $page = max(1, $request->integer('page', 1));
        $perPage = $this->perPage($request->integer('per_page', 15));

        $result = $this->index->stationCapturesPage($stationName, $page, $perPage, [
            'date_from' => $request->string('date_from')->toString() ?: null,
            'date_to' => $request->string('date_to')->toString() ?: null,
        ]);

        $total = $result['total'];
        $totalPages = $result['total_pages'];

        $resource = new PaginatedResource(
            CaptureResource::collection($result['items'])->resolve(),
            $total,
            $page,
            $perPage,
            $totalPages,
            $page < $totalPages,
            $page > 1
        );

        return response()->json($resource);
    }

    /**
     * Get statistics for a specific station.
     */
    public function stats(string $stationName): JsonResponse
    {
        if (!$this->index->exists()) {
            return $this->indexMissingResponse();
        }

        $stats = $this->index->stationStats($stationName);

        if ($stats === null) {
            return response()->json(['detail' => 'Estação não encontrada'], 404);
        }

        $months = $stats['captures_by_month'];
        unset($stats['captures_by_month']);

        return response()->json(array_merge($stats, [
            'captures_by_month' => $months,
        ]));
    }

    /**
     * Clamp the requested page size.
     */
    protected function perPage(int $requested): int
    {
        return max(1, min($requested, 100));
    }

    /**
     * Actionable response when the index has not been built yet.
     */
    protected function indexMissingResponse(): JsonResponse
    {
        return response()->json([
            'detail' => 'Índice de capturas não construído. Execute: php artisan bramon:index',
        ], 503);
    }
}
