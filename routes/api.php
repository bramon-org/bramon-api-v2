<?php

use App\Http\Controllers\Api\V1\Public\CacheController;
use App\Http\Controllers\Api\V1\Public\CaptureController;
use App\Http\Controllers\Api\V1\Public\HealthController;
use App\Http\Controllers\Api\V1\Public\OperatorController;
use App\Http\Controllers\Api\V1\Public\StationController;
use App\Http\Controllers\Api\V1\Public\StatsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| BRAMON API Routes
|--------------------------------------------------------------------------
|
| Public API endpoints for BRAMON meteor observation network.
| All endpoints are under /v1/public prefix.
|
*/

Route::prefix('v1/public')->group(function () {
    // Health & System
    Route::get('/health', [HealthController::class, '__invoke']);
    Route::get('/stats', [StatsController::class, '__invoke']);
    Route::post('/cache/invalidate', [CacheController::class, 'invalidate']);

    // Captures
    Route::get('/captures', [CaptureController::class, 'index']);
    Route::get('/captures/search', [CaptureController::class, 'search']);
    Route::get('/captures/{captureId}', [CaptureController::class, 'show']);
    Route::get('/captures/{captureId}/files/{fileType}', [CaptureController::class, 'file']);

    // Stations
    Route::get('/stations', [StationController::class, 'index']);
    Route::get('/stations/{stationName}', [StationController::class, 'show']);
    Route::get('/stations/{stationName}/captures', [StationController::class, 'captures']);
    Route::get('/stations/{stationName}/stats', [StationController::class, 'stats']);

    // Operators
    Route::get('/operators', [OperatorController::class, 'index']);
    Route::get('/operators/{operatorId}', [OperatorController::class, 'show']);
});