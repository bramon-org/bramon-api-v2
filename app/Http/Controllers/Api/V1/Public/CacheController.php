<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\FileSystemReader;
use Illuminate\Http\JsonResponse;

class CacheController extends Controller
{
    public function __construct(
        protected FileSystemReader $fileReader
    ) {}

    /**
     * Invalidate the cache.
     */
    public function invalidate(): JsonResponse
    {
        $this->fileReader->invalidateCache();

        return response()->json(['message' => 'Cache invalidado com sucesso']);
    }
}