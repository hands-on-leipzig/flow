<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function () {
            // Include external API routes
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api-external.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'keycloak' => \App\Http\Middleware\KeycloakJwtMiddleware::class,
            'api.key' => \App\Http\Middleware\ApiKeyMiddleware::class,
            'api.rate_limit' => \App\Http\Middleware\ApiRateLimitMiddleware::class,
        ]);
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle API exceptions - translate SQL errors to user-friendly messages
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // Only handle API routes
            if (!$request->is('api/*')) {
                return null; // Let Laravel handle non-API routes normally
            }

            // Let validation exceptions pass through (they have their own format)
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return null;
            }

            // Let authorization exceptions pass through
            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                return null;
            }

            // Let authentication exceptions pass through
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return null;
            }

            // Translate all other exceptions (including SQL errors)
            $translated = \App\Services\ErrorTranslationService::translateException($e);
            
            return response()->json([
                'message' => $translated['message'],
                'details' => $translated['details'],
            ], 500);
        });
    })->create();
