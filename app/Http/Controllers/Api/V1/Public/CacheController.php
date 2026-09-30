<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\CaptureIndex;
use App\Services\FileSystemReader;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class CacheController extends Controller
{
    public function __construct(
        protected FileSystemReader $fileReader,
        protected CaptureIndex $index
    ) {}

    /**
     * Invalidate the request-level cache and drop the on-disk index.
     *
     * Dropping the index makes the listing endpoints return 503 until
     * `php artisan bramon:index` runs again, so this is only appropriate when
     * the capture tree is about to be re-indexed.
     */
    #[OA\Post(
        path: '/cache/invalidate',
        tags: ['Cache'],
        summary: 'Invalida o índice em disco',
        description: 'Limpa o cache de leitura e apaga o índice de capturas. Após esta chamada as rotas de listagem passam a devolver 503 até que `php artisan bramon:index` seja executado novamente. Esta rota não exige autenticação: qualquer cliente que alcance a API pode apagar o índice e derrubar as listagens.',
        operationId: 'invalidateCache',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Índice removido',
                content: new OA\JsonContent(
                    type: 'object',
                    required: ['message'],
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Cache invalidado. Execute "php artisan bramon:index" para reconstruir o índice.',
                        ),
                    ],
                ),
            ),
        ],
    )]
    public function invalidate(): JsonResponse
    {
        $this->fileReader->invalidateCache();
        $this->index->flush();

        return response()->json([
            'message' => 'Cache invalidado. Execute "php artisan bramon:index" para reconstruir o índice.',
        ]);
    }
}
