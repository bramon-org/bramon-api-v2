<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Public\CaptureListRequest;
use App\Http\Requests\Api\V1\Public\CaptureSearchRequest;
use App\Http\Resources\Api\V1\Public\CaptureResource;
use App\Http\Resources\Api\V1\Public\PaginatedResource;
use App\Services\DataProcessor;
use App\Services\FileSystemReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class CaptureController extends Controller
{
    public function __construct(
        protected DataProcessor $dataProcessor,
        protected FileSystemReader $fileReader
    ) {}

    /**
     * List captures with pagination and filters.
     */
    public function index(CaptureListRequest $request): JsonResponse
    {
        $useCache = $request->boolean('use_cache', true);
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 15);

        $captures = $this->dataProcessor->processCaptures(
            $this->fileReader->getFiles($useCache)
        );

        // Apply filters
        $filtered = $this->applyFilters($captures, $request);

        // Paginate
        $total = count($filtered);
        $totalPages = (int) ceil($total / $perPage);
        $start = ($page - 1) * $perPage;
        $paginated = array_slice($filtered, $start, $perPage);

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
     * Search captures by term.
     */
    public function search(CaptureSearchRequest $request): JsonResponse
    {
        $useCache = $request->boolean('use_cache', true);
        $query = $request->string('q')->toString();
        $limit = $request->integer('limit', 50);

        $captures = $this->dataProcessor->processCaptures(
            $this->fileReader->getFiles($useCache)
        );

        $qLower = strtolower($query);
        $results = [];

        foreach ($captures as $capture) {
            // Search in station
            if (str_contains(strtolower($capture['station']), $qLower)) {
                $results[] = $capture;
                continue;
            }

            // Search in date
            if (str_contains($capture['capture_date'], $query)
                || str_contains($capture['captured_at'], $query)) {
                $results[] = $capture;
                continue;
            }

            // Search in files
            foreach ($capture['files'] as $file) {
                if (str_contains(strtolower($file['filename']), $qLower)
                    || str_contains(strtolower($file['type']), $qLower)) {
                    $results[] = $capture;
                    break;
                }
            }

            if (count($results) >= $limit) {
                break;
            }
        }

        return response()->json([
            'query' => $query,
            'total' => count($results),
            'results' => CaptureResource::collection(array_slice($results, 0, $limit))->resolve(),
        ]);
    }

    /**
     * Get a specific capture by ID.
     */
    public function show(string $captureId, bool $useCache = true): JsonResponse
    {
        $captures = $this->dataProcessor->processCaptures(
            $this->fileReader->getFiles($useCache)
        );

        foreach ($captures as $capture) {
            if ($capture['id'] === $captureId) {
                return response()->json(new CaptureResource($capture));
            }
        }

        return response()->json(['detail' => 'Captura não encontrada'], 404);
    }

    /**
     * Get a capture file (metadata, preview, thumbnail).
     */
    public function file(string $captureId, string $fileType): JsonResponse
    {
        $captures = $this->dataProcessor->processCaptures(
            $this->fileReader->getFiles(true)
        );

        foreach ($captures as $capture) {
            if ($capture['id'] === $captureId) {
                foreach ($capture['files'] as $file) {
                    if ($file['type'] === $fileType) {
                        $filePath = $this->fileReader->getBaseDir() . '/' . $file['url'];

                        if (file_exists($filePath)) {
                            return response()->file($filePath, [
                                'Content-Disposition' => 'inline; filename="' . $file['filename'] . '"',
                            ]);
                        }

                        return response()->json(['detail' => 'Arquivo não encontrado'], 404);
                    }
                }

                return response()->json(['detail' => "Arquivo {$fileType} não encontrado"], 404);
            }
        }

        return response()->json(['detail' => 'Captura não encontrada'], 404);
    }

    /**
     * Apply filters to captures.
     */
    protected function applyFilters(array $captures, CaptureListRequest $request): array
    {
        $filtered = $captures;

        if ($station = $request->string('station')->toString()) {
            $filtered = array_filter($filtered, fn($c) => $c['station'] === $station);
        }

        if ($dateFrom = $request->string('date_from')->toString()) {
            $filtered = array_filter($filtered, fn($c) => $c['captured_at'] >= $dateFrom);
        }

        if ($dateTo = $request->string('date_to')->toString()) {
            $dateToEnd = $dateTo . ' 23:59:59';
            $filtered = array_filter($filtered, fn($c) => $c['captured_at'] <= $dateToEnd);
        }

        if ($request->has('has_metadata')) {
            $hasMetadata = $request->boolean('has_metadata');
            $filtered = array_filter($filtered, fn($c) => $c['has_metadata'] === $hasMetadata);
        }

        if ($request->has('has_preview')) {
            $hasPreview = $request->boolean('has_preview');
            $filtered = array_filter($filtered, fn($c) => $c['has_preview'] === $hasPreview);
        }

        return array_values($filtered);
    }
}