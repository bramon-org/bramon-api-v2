<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Public\StationListRequest;
use App\Http\Requests\Api\V1\Public\StationCapturesRequest;
use App\Http\Resources\Api\V1\Public\CaptureResource;
use App\Http\Resources\Api\V1\Public\PaginatedResource;
use App\Http\Resources\Api\V1\Public\StationResource;
use App\Services\DataProcessor;
use App\Services\FileSystemReader;
use Illuminate\Http\JsonResponse;

class StationController extends Controller
{
    public function __construct(
        protected DataProcessor $dataProcessor,
        protected FileSystemReader $fileReader
    ) {}

    /**
     * List stations with pagination.
     */
    public function index(StationListRequest $request): JsonResponse
    {
        $useCache = $request->boolean('use_cache', true);
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 15);
        $activeOnly = $request->boolean('active_only', false);

        $captures = $this->dataProcessor->processCaptures(
            $this->fileReader->getFiles($useCache)
        );
        $stations = $this->dataProcessor->processStations($captures);

        if ($activeOnly) {
            $stations = array_filter($stations, fn($s) => $s['active']);
        }

        $stations = array_values($stations);

        $total = count($stations);
        $totalPages = (int) ceil($total / $perPage);
        $start = ($page - 1) * $perPage;
        $paginated = array_slice($stations, $start, $perPage);

        $resource = new PaginatedResource(
            StationResource::collection($paginated)->resolve(),
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
    public function show(string $stationName, bool $useCache = true): JsonResponse
    {
        $captures = $this->dataProcessor->processCaptures(
            $this->fileReader->getFiles($useCache)
        );
        $stations = $this->dataProcessor->processStations($captures);

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
        $useCache = $request->boolean('use_cache', true);
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 15);

        $captures = $this->dataProcessor->processCaptures(
            $this->fileReader->getFiles($useCache)
        );

        // Filter by station
        $stationCaptures = array_filter($captures, fn($c) => $c['station'] === $stationName);

        // Filter by date
        if ($dateFrom = $request->string('date_from')->toString()) {
            $stationCaptures = array_filter($stationCaptures, fn($c) => $c['captured_at'] >= $dateFrom);
        }

        if ($dateTo = $request->string('date_to')->toString()) {
            $dateToEnd = $dateTo . ' 23:59:59';
            $stationCaptures = array_filter($stationCaptures, fn($c) => $c['captured_at'] <= $dateToEnd);
        }

        $stationCaptures = array_values($stationCaptures);

        if (empty($stationCaptures)) {
            return response()->json(['detail' => 'Estação não encontrada'], 404);
        }

        $total = count($stationCaptures);
        $totalPages = (int) ceil($total / $perPage);
        $start = ($page - 1) * $perPage;
        $paginated = array_slice($stationCaptures, $start, $perPage);

        $resource = new PaginatedResource(
            CaptureResource::collection($paginated)->resolve(),
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
    public function stats(string $stationName, bool $useCache = true): JsonResponse
    {
        $captures = $this->dataProcessor->processCaptures(
            $this->fileReader->getFiles($useCache)
        );

        $stationCaptures = array_filter($captures, fn($c) => $c['station'] === $stationName);
        $stationCaptures = array_values($stationCaptures);

        if (empty($stationCaptures)) {
            return response()->json(['detail' => 'Estação não encontrada'], 404);
        }

        $dates = array_column($stationCaptures, 'captured_at');
        $months = [];

        foreach ($stationCaptures as $capture) {
            $monthKey = substr($capture['capture_date'], 0, 6);
            $months[$monthKey] = ($months[$monthKey] ?? 0) + 1;
        }

        return response()->json([
            'station' => $stationName,
            'total_captures' => count($stationCaptures),
            'first_capture' => min($dates),
            'last_capture' => max($dates),
            'captures_by_month' => $months,
            'has_metadata_count' => count(array_filter($stationCaptures, fn($c) => $c['has_metadata'])),
            'has_preview_count' => count(array_filter($stationCaptures, fn($c) => $c['has_preview'])),
            'has_thumbnail_count' => count(array_filter($stationCaptures, fn($c) => $c['has_thumbnail'])),
        ]);
    }
}