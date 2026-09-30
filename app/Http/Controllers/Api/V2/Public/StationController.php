<?php

namespace App\Http\Controllers\Api\V2\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\Public\StationListRequest;
use App\Http\Requests\Api\V2\Public\StationCapturesRequest;
use App\Http\Resources\Api\V2\Public\CaptureResource;
use App\Http\Resources\Api\V2\Public\PaginatedResource;
use App\Http\Resources\Api\V2\Public\StationResource;
use App\Services\CaptureIndex;
use App\Services\OperatorReader;
use App\Services\StationReader;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Station endpoints.
 *
 * Station listing and lookups are read directly from stations.json
 * (BRAMON_STATIONS_FILE), independent of the capture index. Only the
 * capture-related endpoints (captures, stats) require the prebuilt index.
 */
class StationController extends Controller
{
    public function __construct(
        protected StationReader $stationReader,
        protected OperatorReader $operatorReader,
        protected CaptureIndex $index
    ) {}

    /**
     * List stations with pagination.
     */
    #[OA\Get(
        path: '/stations',
        tags: ['Stations'],
        summary: 'Lista estações',
        description: 'Devolve as estações paginadas, ordenadas alfabeticamente por nome. Todos os campos vêm do manifesto do índice; nenhuma pasta de capturas é aberta. O nó "operador" traz o operador responsável, com o mesmo formato de /operators, resolvido pelo nome da estação.',
        operationId: 'listStations',
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/page'),
            new OA\Parameter(ref: '#/components/parameters/per_page'),
            new OA\Parameter(ref: '#/components/parameters/active_only'),
            new OA\Parameter(ref: '#/components/parameters/use_cache'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Página de estações retornada',
                content: new OA\JsonContent(ref: '#/components/schemas/PaginatedStations'),
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
    public function index(StationListRequest $request): JsonResponse
    {
        $page = max(1, $request->integer('page', 1));
        $perPage = $this->perPage($request->integer('per_page', 15));
        $activeOnly = $request->boolean('active_only', false);

        // Read stations directly from stations.json (BRAMON_STATIONS_FILE)
        $stations = $this->stationReader->getStations();
        $operators = $this->operatorReader->getOperators();

        $result = [];
        foreach ($stations as $station) {
            $name = $station['name'] ?? '';
            $operator = $this->operatorReader->findOperatorForStation($name, $operators);
            $isActive = $operator !== null;

            if ($activeOnly && !$isActive) {
                continue;
            }

            $result[] = [
                'id' => $station['id'] ?? $station['codigo'] ?? $name,
                'name' => $name,
                'operador' => $operator ? [
                    'id' => $operator['id'] ?? $operator['sigla'] ?? '',
                    'name' => $operator['nome'] ?? $operator['name'] ?? '',
                    'email' => OperatorReader::emailFromName($operator['nome'] ?? $operator['name'] ?? ''),
                    'role' => $operator['role'] ?? $operator['funcao'] ?? null,
                    'visible' => $operator['visible'] ?? true,
                    'active' => true,
                    'station_count' => 0,
                    'stations' => [],
                ] : null,
                'city' => $station['city'] ?? $station['cidade'] ?? null,
                'state' => $station['state'] ?? $station['estado'] ?? null,
                'country' => $station['country'] ?? $station['pais'] ?? null,
                'latitude' => $station['latitude'] ?? null,
                'longitude' => $station['longitude'] ?? null,
                'altitude' => $station['altitude'] ?? null,
                'azimuth' => $station['azimuth'] ?? null,
                'elevation' => $station['elevation'] ?? null,
                'fov' => $station['fov'] ?? null,
                'camera_model' => $station['camera_model'] ?? null,
                'camera_lens' => $station['camera_lens'] ?? null,
                'camera_capture' => $station['camera_capture'] ?? null,
                'active' => $isActive,
                'visible' => $station['visible'] ?? true,
                'capture_count' => 0,
                'first_capture' => null,
                'last_capture' => null,
            ];
        }

        // Sort by name
        usort($result, fn($a, $b) => strcmp($a['name'], $b['name']));

        $total = count($result);
        $totalPages = (int) ceil($total / $perPage);
        $start = ($page - 1) * $perPage;

        $resource = new PaginatedResource(
            StationResource::collection(array_slice($result, $start, $perPage))->resolve(),
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
    #[OA\Get(
        path: '/stations/{stationName}',
        tags: ['Stations'],
        summary: 'Detalha uma estação',
        description: 'Devolve uma estação pelo seu código/nome, incluindo a contagem de capturas, a janela de atividade e o operador responsável (nó "operador", mesmo formato de /operators). Os metadados vêm de stations.json; apenas os agregados de captura vêm do índice.',
        operationId: 'getStation',
        parameters: [
            new OA\Parameter(
                name: 'stationName',
                in: 'path',
                required: true,
                description: 'Código ou nome da estação.',
                schema: new OA\Schema(type: 'string', example: 'STA07'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Estação encontrada',
                content: new OA\JsonContent(ref: '#/components/schemas/Station'),
            ),
            new OA\Response(
                response: 404,
                description: 'Estação não encontrada',
                content: new OA\JsonContent(ref: '#/components/schemas/Error'),
            ),
            new OA\Response(
                response: 503,
                description: 'Índice de capturas não construído',
                content: new OA\JsonContent(ref: '#/components/schemas/IndexNotReady'),
            ),
        ],
    )]
    public function show(string $stationName): JsonResponse
    {
        // Read station directly from stations.json (BRAMON_STATIONS_FILE)
        $stations = $this->stationReader->getStations();
        $operators = $this->operatorReader->getOperators();

        foreach ($stations as $station) {
            $name = $station['name'] ?? '';
            if ($name === $stationName) {
                $operator = $this->operatorReader->findOperatorForStation($name, $operators);

                return response()->json(new StationResource([
                    'id' => $station['id'] ?? $station['codigo'] ?? $name,
                    'name' => $name,
                    'operador' => $operator ? [
                        'id' => $operator['id'] ?? $operator['sigla'] ?? '',
                        'name' => $operator['nome'] ?? $operator['name'] ?? '',
                        'email' => OperatorReader::emailFromName($operator['nome'] ?? $operator['name'] ?? ''),
                        'role' => $operator['role'] ?? $operator['funcao'] ?? null,
                        'visible' => $operator['visible'] ?? true,
                        'active' => true,
                        'station_count' => 0,
                        'stations' => [],
                    ] : null,
                    'city' => $station['city'] ?? $station['cidade'] ?? null,
                    'state' => $station['state'] ?? $station['estado'] ?? null,
                    'country' => $station['country'] ?? $station['pais'] ?? null,
                    'latitude' => $station['latitude'] ?? null,
                    'longitude' => $station['longitude'] ?? null,
                    'altitude' => $station['altitude'] ?? null,
                    'azimuth' => $station['azimuth'] ?? null,
                    'elevation' => $station['elevation'] ?? null,
                    'fov' => $station['fov'] ?? null,
                    'camera_model' => $station['camera_model'] ?? null,
                    'camera_lens' => $station['camera_lens'] ?? null,
                    'camera_capture' => $station['camera_capture'] ?? null,
                    'active' => $operator !== null,
                    'visible' => $station['visible'] ?? true,
                    'capture_count' => 0,
                    'first_capture' => null,
                    'last_capture' => null,
                ]));
            }
        }

        return response()->json(['detail' => 'Estação não encontrado'], 404);
    }

    /**
     * Get captures for a specific station.
     */
    #[OA\Get(
        path: '/stations/{stationName}/captures',
        tags: ['Stations'],
        summary: 'Lista capturas de uma estação',
        description: 'Paginação por busca direta (seek) no índice: a página é lida deslocando o cursor para a posição correspondente, sem varrer o índice inteiro. As capturas vêm ordenadas por captured_at descendente.',
        operationId: 'listStationCaptures',
        parameters: [
            new OA\Parameter(
                name: 'stationName',
                in: 'path',
                required: true,
                description: 'Código ou nome da estação.',
                schema: new OA\Schema(type: 'string', example: 'STA07'),
            ),
            new OA\Parameter(ref: '#/components/parameters/page'),
            new OA\Parameter(ref: '#/components/parameters/per_page'),
            new OA\Parameter(ref: '#/components/parameters/date_from'),
            new OA\Parameter(ref: '#/components/parameters/date_to'),
            new OA\Parameter(ref: '#/components/parameters/use_cache'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Página de capturas da estação',
                content: new OA\JsonContent(ref: '#/components/schemas/PaginatedCaptures'),
            ),
            new OA\Response(
                response: 404,
                description: 'Estação não encontrada',
                content: new OA\JsonContent(ref: '#/components/schemas/Error'),
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
    #[OA\Get(
        path: '/stations/{stationName}/stats',
        tags: ['Stations'],
        summary: 'Estatísticas de uma estação',
        description: 'Total de capturas, primeira e última captura e a distribuição mensal. Todos os valores já estão pré-computados no manifesto do índice.',
        operationId: 'getStationStats',
        parameters: [
            new OA\Parameter(
                name: 'stationName',
                in: 'path',
                required: true,
                description: 'Código ou nome da estação.',
                schema: new OA\Schema(type: 'string', example: 'STA07'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Estatísticas da estação',
                content: new OA\JsonContent(ref: '#/components/schemas/StationStats'),
            ),
            new OA\Response(
                response: 404,
                description: 'Estação não encontrada',
                content: new OA\JsonContent(ref: '#/components/schemas/Error'),
            ),
            new OA\Response(
                response: 503,
                description: 'Índice de capturas não construído',
                content: new OA\JsonContent(ref: '#/components/schemas/IndexNotReady'),
            ),
        ],
    )]
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
