<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\FileSystemReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;

class HealthController extends Controller
{
    public function __construct(
        protected FileSystemReader $fileReader
    ) {}

    public function __invoke(): JsonResponse
    {
        $baseDir = Config::get('bramon.base_dir');
        $baseDirExists = is_dir($baseDir);

        return response()->json([
            'status' => $baseDirExists ? 'healthy' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'base_dir' => $baseDir,
            'base_dir_exists' => $baseDirExists,
        ]);
    }
}