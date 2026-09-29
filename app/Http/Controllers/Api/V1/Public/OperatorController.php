<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Public\OperatorListRequest;
use App\Http\Resources\Api\V1\Public\OperatorResource;
use App\Http\Resources\Api\V1\Public\PaginatedResource;
use App\Services\DataProcessor;
use App\Services\FileSystemReader;
use Illuminate\Http\JsonResponse;

class OperatorController extends Controller
{
    public function __construct(
        protected DataProcessor $dataProcessor,
        protected FileSystemReader $fileReader
    ) {}

    /**
     * List operators with pagination.
     */
    public function index(OperatorListRequest $request): JsonResponse
    {
        $useCache = $request->boolean('use_cache', true);
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 15);
        $activeOnly = $request->boolean('active_only', false);

        $captures = $this->dataProcessor->processCaptures(
            $this->fileReader->getFiles($useCache)
        );
        $stations = $this->dataProcessor->processStations($captures);
        $operators = $this->dataProcessor->processOperators($stations);

        if ($activeOnly) {
            $operators = array_filter($operators, fn($o) => $o['active']);
        }

        $operators = array_values($operators);

        $total = count($operators);
        $totalPages = (int) ceil($total / $perPage);
        $start = ($page - 1) * $perPage;
        $paginated = array_slice($operators, $start, $perPage);

        $resource = new PaginatedResource(
            OperatorResource::collection($paginated)->resolve(),
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
     * Get a specific operator by ID.
     */
    public function show(string $operatorId, bool $useCache = true): JsonResponse
    {
        $captures = $this->dataProcessor->processCaptures(
            $this->fileReader->getFiles($useCache)
        );
        $stations = $this->dataProcessor->processStations($captures);
        $operators = $this->dataProcessor->processOperators($stations);

        foreach ($operators as $operator) {
            if ($operator['id'] === $operatorId) {
                return response()->json(new OperatorResource($operator));
            }
        }

        return response()->json(['detail' => 'Operador não encontrado'], 404);
    }
}