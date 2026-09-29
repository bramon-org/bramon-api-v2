<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Public\HealthController;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'version' => '2.0.0',
        'documentation' => '/api/documentation',
        'health' => '/api/v1/public/health',
    ]);
});

Route::get('/health', HealthController::class);