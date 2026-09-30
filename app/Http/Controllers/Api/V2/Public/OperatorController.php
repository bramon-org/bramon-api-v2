<?php

namespace App\Http\Controllers\Api\V2\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\Public\OperatorListRequest;
use App\Http\Resources\Api\V2\Public\OperatorResource;
use App\Http\Resources\Api\V2\Public\PaginatedResource;
use App\Services\OperatorReader;
use App\Services\StationReader;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Operator endpoints.
 *
 * Operators are read directly from operators.json (BRAMON_OPERATORS_FILE),
 * independent of the capture index. Station names are resolved from
 * stations.json (BRAMON_STATIONS_FILE).
 */
class OperatorController extends Controller
{
    public function __construct(
        protected OperatorReader $operatorReader,
        protected StationReader $stationReader
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
        $page = max(1, $request->integer('page', 1));
        $perPage = $this->perPage($request->integer('per_page', 15));
        $activeOnly = $request->boolean('active_only', false);

        $operators = $this->operatorReader->getOperators();
        $stations = $this->stationReader->getStationsIndexed();

        $result = [];
        foreach ($operators as $operator) {
            $linkedStations = $this->operatorReader->getLinkedStations($operator, $stations);
            $isActive = count($linkedStations) > 0;

            if ($activeOnly && !$isActive) {
                continue;
            }

            $result[] = [
                'id' => $operator['id'] ?? $operator['sigla'] ?? '',
                'name' => $operator['nome'] ?? $operator['name'] ?? '',
                'email' => OperatorReader::emailFromName($operator['nome'] ?? $operator['name'] ?? ''),
                'role' => $operator['role'] ?? $operator['funcao'] ?? null,
                'visible' => $operator['visible'] ?? true,
                'active' => $isActive,
                'station_count' => count($linkedStations),
                'stations' => $linkedStations,
                'latitude' => $operator['latitude'] ?? null,
                'longitude' => $operator['longitude'] ?? null,
                'altitude' => $operator['altitude'] ?? null,
            ];
        }

        $total = count($result);
        $totalPages = (int) ceil($total / $perPage);
        $start = ($page - 1) * $perPage;

        $resource = new PaginatedResource(
            OperatorResource::collection(array_slice($result, $start, $perPage))->resolve(),
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
        $operators = $this->operatorReader->getOperators();
        $stations = $this->stationReader->getStationsIndexed();

        foreach ($operators as $operator) {
            $id = $operator['id'] ?? $operator['sigla'] ?? '';
            if ($id === $operatorId) {
                $linkedStations = $this->operatorReader->getLinkedStations($operator, $stations);

                return response()->json(new OperatorResource([
                    'id' => $id,
                    'name' => $operator['nome'] ?? $operator['name'] ?? '',
                    'email' => OperatorReader::emailFromName($operator['nome'] ?? $operator['name'] ?? ''),
                    'role' => $operator['role'] ?? $operator['funcao'] ?? 'operator',
                    'visible' => $operator['visible'] ?? true,
                    'active' => count($linkedStations) > 0,
                    'station_count' => count($linkedStations),
                    'stations' => $linkedStations,
                    'latitude' => $operator['latitude'] ?? null,
                    'longitude' => $operator['longitude'] ?? null,
                    'altitude' => $operator['altitude'] ?? null,
                ]));
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
}
