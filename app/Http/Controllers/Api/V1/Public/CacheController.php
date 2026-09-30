<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\CaptureIndex;
use App\Services\FileSystemReader;
use Illuminate\Http\JsonResponse;

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
    public function invalidate(): JsonResponse
    {
        $this->fileReader->invalidateCache();
        $this->index->flush();

        return response()->json([
            'message' => 'Cache invalidado. Execute "php artisan bramon:index" para reconstruir o índice.',
        ]);
    }
}
