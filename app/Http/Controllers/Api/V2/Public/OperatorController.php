<?php

namespace App\Http\Controllers\Api\V2\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\Public\OperatorListRequest;
use App\Http\Resources\Api\V2\Public\OperatorResource;
use App\Http\Resources\Api\V2\Public\PaginatedResource;
use App\Services\CaptureIndex;
use App\Services\DataProcessor;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Operator endpoints.
 *
 * Operators are derived from operators.json and only need the station name
 * list, so these routes read the small station manifest rather than scanning
 * the captures tree (which is what exhausted the memory limit).
 */
class OperatorController extends Controller
{
    public function __construct(
        protected DataProcessor $dataProcessor,
        protected CaptureIndex $index
    ) {}

    /**
     * List operators with pagination.
     */
    #[OA\Get(
        path: '/operators',
        tags: ['Operators'],
        summary: 'Lista operadores',
        description: 'Devolve os operadores paginados. Cada operador carrega a lista de estações sob sua responsabilidade. Construído a partir de operators.json cruzado com as estações do índice, sem varrer o disco de capturas.',
        operationId: 'listOperators',
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/page'),
            new OA\Parameter(ref: '#/components/parameters/per_page'),
            new OA\Parameter(ref: '#/components/parameters/active_only'),
            new OA\Parameter(ref: '#/components/parameters/use_cache'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Página de operadores retornada',
                content: new OA\JsonContent(ref: '#/components/schemas/PaginatedOperators'),
            ),
            new OA\Response(
                response: 422,
                description: 'Parâmetros de query inválidos',
                content: new OA\JsonContent(ref: '#/components/schemas/Error'),
            ),
            new OA\Response(
                response: 503,
                description: 'Índice de capturas não construído',
                content: new OA\JsonContent(ref: '#/components/schemas/IndexNotReady'),
            ),
        ],
    )]
    public function index(OperatorListRequest $request): JsonResponse
    {
        if (!$this->index->exists()) {
            return $this->indexMissingResponse();
        }

        $page = max(1, $request->integer('page', 1));
        $perPage = $this->perPage($request->integer('per_page', 15));
        $activeOnly = $request->boolean('active_only', false);

        $stations = $this->dataProcessor->stationsFromIndex($this->index);
        $operators = $this->dataProcessor->processOperators($stations);

        if ($activeOnly) {
            $operators = array_filter($operators, fn($o) => $o['active']);
        }

        $operators = array_values($operators);

        $total = count($operators);
        $totalPages = (int) ceil($total / $perPage);
        $start = ($page - 1) * $perPage;

        $resource = new PaginatedResource(
            OperatorResource::collection(array_slice($operators, $start, $perPage))->resolve(),
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
    #[OA\Get(
        path: '/operators/{operatorId}',
        tags: ['Operators'],
        summary: 'Detalha um operador',
        description: 'Devolve um operador pelo seu identificador, com a lista de estações vinculadas.',
        operationId: 'getOperator',
        parameters: [
            new OA\Parameter(
                name: 'operatorId',
                in: 'path',
                required: true,
                description: 'Identificador do operador.',
                schema: new OA\Schema(type: 'string', example: 'TLP'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Operador encontrado',
                content: new OA\JsonContent(ref: '#/components/schemas/Operator'),
            ),
            new OA\Response(
                response: 404,
                description: 'Operador não encontrado',
                content: new OA\JsonContent(ref: '#/components/schemas/Error'),
            ),
            new OA\Response(
                response: 503,
                description: 'Índice de capturas não construído',
                content: new OA\JsonContent(ref: '#/components/schemas/IndexNotReady'),
            ),
        ],
    )]
    public function show(string $operatorId): JsonResponse
    {
        if (!$this->index->exists()) {
            return $this->indexMissingResponse();
        }

        $stations = $this->dataProcessor->stationsFromIndex($this->index);
        $operators = $this->dataProcessor->processOperators($stations);

        foreach ($operators as $operator) {
            if ($operator['id'] === $operatorId) {
                return response()->json(new OperatorResource($operator));
            }
        }

        return response()->json(['detail' => 'Operador não encontrado'], 404);
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
