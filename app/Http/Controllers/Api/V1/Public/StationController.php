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
use OpenApi\Attributes as OA;

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
    #[OA\Get(
        path: '/stations',
        tags: ['Stations'],
        summary: 'Lista estações',
        description: 'Devolve as estações paginadas, ordenadas alfabeticamente por nome. Todos os campos vêm do manifesto do índice; nenhuma pasta de capturas é aberta.',
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
    #[OA\Get(
        path: '/stations/{stationName}',
        tags: ['Stations'],
        summary: 'Detalha uma estação',
        description: 'Devolve uma estação pelo seu código/nome, incluindo a contagem de capturas e a janela de atividade. Os metadados vêm de stations.json; apenas os agregados de captura vêm do índice.',
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
