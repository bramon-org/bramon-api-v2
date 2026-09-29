<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Public\StatsRequest;
use App\Http\Resources\Api\V1\Public\StatsResource;
use App\Services\DataProcessor;
use App\Services\FileSystemReader;
use Illuminate\Http\JsonResponse;

class StatsController extends Controller
{
    public function __construct(
        protected DataProcessor $dataProcessor,
        protected FileSystemReader $fileReader
    ) {}

    public function __invoke(StatsRequest $request): JsonResponse
    {
        $useCache = $request->boolean('use_cache', true);

        $files = $this->fileReader->getFiles($useCache);
        $captures = $this->dataProcessor->processCaptures($files);
        $stations = $this->dataProcessor->processStations($captures);
        $operators = $this->dataProcessor->processOperators($stations);

        $stats = $this->dataProcessor->calculateStats($captures, $stations, $operators, $files);

        return response()->json(new StatsResource($stats));
    }
}