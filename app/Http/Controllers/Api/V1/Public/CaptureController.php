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
use OpenApi\Attributes as OA;
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
    #[OA\Get(
        path: '/captures',
        tags: ['Captures'],
        summary: 'Lista capturas',
        description: 'Paginação por busca direta (seek) no índice, ordenada por captured_at descendente. Filtros por estação, intervalo de datas e presença de metadados ou preview são aplicados sobre o índice. O custo de memória de cada página é proporcional a per_page, não ao total de arquivos em disco.',
        operationId: 'listCaptures',
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/page'),
            new OA\Parameter(ref: '#/components/parameters/per_page'),
            new OA\Parameter(ref: '#/components/parameters/station'),
            new OA\Parameter(ref: '#/components/parameters/date_from'),
            new OA\Parameter(ref: '#/components/parameters/date_to'),
            new OA\Parameter(ref: '#/components/parameters/has_metadata'),
            new OA\Parameter(ref: '#/components/parameters/has_preview'),
            new OA\Parameter(ref: '#/components/parameters/use_cache'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Página de capturas retornada',
                content: new OA\JsonContent(ref: '#/components/schemas/PaginatedCaptures'),
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
    #[OA\Get(
        path: '/captures/search',
        tags: ['Captures'],
        summary: 'Busca capturas',
        description: 'Busca livre por estação, identificador ou caminho nos arquivos. Os resultados são ordenados do mais recente para o mais antigo. Diferente das demais listagens, esta rota não é paginada: devolve apenas o recorte superior definido por limit.',
        operationId: 'searchCaptures',
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/q'),
            new OA\Parameter(ref: '#/components/parameters/limit'),
            new OA\Parameter(ref: '#/components/parameters/use_cache'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resultados da busca',
                content: new OA\JsonContent(ref: '#/components/schemas/SearchResponse'),
            ),
            new OA\Response(
                response: 422,
                description: 'Parâmetros de query inválidos ou q ausente',
                content: new OA\JsonContent(ref: '#/components/schemas/Error'),
            ),
            new OA\Response(
                response: 503,
                description: 'Índice de capturas não construído',
                content: new OA\JsonContent(ref: '#/components/schemas/IndexNotReady'),
            ),
        ],
    )]
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
    #[OA\Get(
        path: '/captures/{captureId}',
        tags: ['Captures'],
        summary: 'Detalha uma captura',
        description: 'Devolve uma captura pelo seu identificador, com a lista de arquivos associados (metadados, preview, thumbnail).',
        operationId: 'getCapture',
        parameters: [
            new OA\Parameter(
                name: 'captureId',
                in: 'path',
                required: true,
                description: 'Identificador da captura.',
                schema: new OA\Schema(type: 'string', example: 'e99854fa685b4adf23084bc9200f0fcb'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Captura encontrada',
                content: new OA\JsonContent(ref: '#/components/schemas/Capture'),
            ),
            new OA\Response(
                response: 404,
                description: 'Captura não encontrada',
                content: new OA\JsonContent(ref: '#/components/schemas/Error'),
            ),
            new OA\Response(
                response: 503,
                description: 'Índice de capturas não construído',
                content: new OA\JsonContent(ref: '#/components/schemas/IndexNotReady'),
            ),
        ],
    )]
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
    #[OA\Get(
        path: '/captures/{captureId}/files/{fileType}',
        tags: ['Captures'],
        summary: 'Baixa um arquivo da captura',
        description: 'Serve o arquivo binário associado à captura. O caminho resolvido é validado contra o diretório base antes de servir, de modo que travessia de diretório é rejeitada. Em caso de sucesso a resposta é binária; nos demais casos o corpo é JSON de erro.',
        operationId: 'getCaptureFile',
        parameters: [
            new OA\Parameter(
                name: 'captureId',
                in: 'path',
                required: true,
                description: 'Identificador da captura.',
                schema: new OA\Schema(type: 'string', example: 'e99854fa685b4adf23084bc9200f0fcb'),
            ),
            new OA\Parameter(
                name: 'fileType',
                in: 'path',
                required: true,
                description: 'Tipo do arquivo a servir.',
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['metadata', 'preview', 'thumbnail', 'analyze'],
                    example: 'thumbnail',
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conteúdo do arquivo, servido do disco com o nome no cabeçalho Content-Disposition. O Content-Type acompanha o arquivo, portanto a resposta 200 aparece abaixo com os dois tipos possíveis.',
                content: [
                    new OA\MediaType(
                        mediaType: 'image/jpeg',
                        schema: new OA\Schema(type: 'string', format: 'binary'),
                    ),
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(type: 'string', format: 'binary'),
                    ),
                ],
            ),
            new OA\Response(
                response: 404,
                description: 'Captura, arquivo ou caminho inexistente',
                content: new OA\JsonContent(ref: '#/components/schemas/Error'),
            ),
            new OA\Response(
                response: 503,
                description: 'Índice de capturas não construído',
                content: new OA\JsonContent(ref: '#/components/schemas/IndexNotReady'),
            ),
        ],
    )]
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
