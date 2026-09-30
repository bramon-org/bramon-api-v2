<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Public\CaptureListRequest;
use App\Http\Requests\Api\V1\Public\CaptureSearchRequest;
use App\Http\Resources\Api\V1\Public\CaptureResource;
use App\Http\Resources\Api\V1\Public\PaginatedResource;
use App\Services\CaptureIndex;
use App\Services\FileSystemReader;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Capture endpoints.
 *
 * Served from the prebuilt CaptureIndex with seek-based pagination, so a page
 * costs O(per_page) instead of loading every file in the captures tree.
 */
class CaptureController extends Controller
{
    public function __construct(
        protected FileSystemReader $fileReader,
        protected CaptureIndex $index
    ) {}

    /**
     * List captures with pagination and filters.
     */
    public function index(CaptureListRequest $request): JsonResponse
    {
        if (!$this->index->exists()) {
            return $this->indexMissingResponse();
        }

        $page = max(1, $request->integer('page', 1));
        $perPage = $this->perPage($request->integer('per_page', 15));

        $result = $this->index->capturesPage($page, $perPage, $this->filters($request));

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
     * Search captures by term.
     */
    public function search(CaptureSearchRequest $request): JsonResponse
    {
        if (!$this->index->exists()) {
            return $this->indexMissingResponse();
        }

        $query = $request->string('q')->toString();
        $limit = max(1, min($request->integer('limit', 50), 200));

        // Results are ordered newest first, so page 1 is the top slice.
        $result = $this->index->capturesPage(1, $limit, ['query' => $query ?: null]);

        return response()->json([
            'query' => $query,
            'total' => $result['total'],
            'results' => CaptureResource::collection($result['items'])->resolve(),
        ]);
    }

    /**
     * Get a specific capture by ID.
     */
    public function show(string $captureId): JsonResponse
    {
        if (!$this->index->exists()) {
            return $this->indexMissingResponse();
        }

        $capture = $this->index->captureById($captureId);

        if ($capture === null) {
            return response()->json(['detail' => 'Captura não encontrada'], 404);
        }

        return response()->json(new CaptureResource($capture));
    }

    /**
     * Get a capture file (metadata, preview, thumbnail).
     *
     * Returns a BinaryFileResponse on success, so the declared type is the
     * Symfony response base class rather than JsonResponse.
     */
    public function file(string $captureId, string $fileType): Response
    {
        if (!$this->index->exists()) {
            return $this->indexMissingResponse();
        }

        $capture = $this->index->captureById($captureId);

        if ($capture === null) {
            return response()->json(['detail' => 'Captura não encontrada'], 404);
        }

        foreach ((array) ($capture['files'] ?? []) as $file) {
            if (($file['type'] ?? null) !== $fileType) {
                continue;
            }

            // Guard against path traversal before serving from disk.
            $filePath = $this->fileReader->getBaseDir() . '/' . ltrim((string) $file['url'], '/');
            $realBase = realpath($this->fileReader->getBaseDir());
            $realFile = realpath($filePath);

            if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
                return response()->json(['detail' => 'Arquivo não encontrado'], 404);
            }

            if (!is_file($realFile)) {
                return response()->json(['detail' => 'Arquivo não encontrado'], 404);
            }

            return response()->file($realFile, [
                'Content-Disposition' => 'inline; filename="' . basename($realFile) . '"',
            ]);
        }

        return response()->json(['detail' => "Arquivo {$fileType} não encontrado"], 404);
    }

    /**
     * Translate the request into index filters.
     *
     * @return array<string,mixed>
     */
    protected function filters(CaptureListRequest $request): array
    {
        return [
            'station' => $request->string('station')->toString() ?: null,
            'date_from' => $request->string('date_from')->toString() ?: null,
            'date_to' => $request->string('date_to')->toString() ?: null,
            'has_metadata' => $request->has('has_metadata') ? $request->boolean('has_metadata') : null,
            'has_preview' => $request->has('has_preview') ? $request->boolean('has_preview') : null,
        ];
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
