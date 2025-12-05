<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\External\EventController;
use App\Http\Controllers\External\PlanController;
use App\Http\Controllers\External\HealthController;

/*
|--------------------------------------------------------------------------
| External API Routes
|--------------------------------------------------------------------------
|
| These routes are for external applications to interact with FLOW.
| They use API key authentication instead of Keycloak JWT tokens.
|
*/

Route::prefix('external')->middleware([
    'api',
    'api.key',           // API key authentication
    'api.rate_limit',    // Rate limiting
])->group(function () {
    
    // Health check endpoint
    Route::get('/health', [HealthController::class, 'check']);
    
    // Events endpoints
    Route::prefix('events')->group(function () {
        Route::get('/', [EventController::class, 'index']);
        Route::get('/{id}', [EventController::class, 'show']);
        Route::get('/slug/{slug}', [EventController::class, 'showBySlug']);
        Route::put('/draht/{drahtId}', [EventController::class, 'updateByDrahtId']);
    });
    
    // Plans endpoints (read-only for external)
    Route::prefix('plans')->group(function () {
        Route::get('/event/{eventId}', [PlanController::class, 'showByEvent']);
        Route::get('/{id}', [PlanController::class, 'show']);
        Route::get('/{id}/activities', [PlanController::class, 'activities']);
    });
    
    // Add more endpoints as needed
});

