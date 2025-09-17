<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/**
 * Alle PHP-Dateien direkt unter legacy/generator/extra
 * 1:1 per URL aufrufen – aber im Laravel-Kontext.
 *
 * Beispiel:
 *  URL: /legacy/generator/extra/generator_test_frame.php
 *  Datei: base_path('legacy/generator/extra/generator_test_frame.php')
 */
Route::any('legacy/generator/extra/{file}', function (string $file) {
    // Nur *.php erlauben (kein Slash im Dateinamen -> nur dieses Verzeichnis, keine Subordner)
    if (!preg_match('/^[A-Za-z0-9._-]+\.php$/', $file)) {
        abort(404);
    }

    $baseDir   = realpath(base_path('legacy/generator/extra'));
    $target    = realpath($baseDir . DIRECTORY_SEPARATOR . $file);

    // Sicherheitsgurt: existiert die Datei und liegt sie wirklich in baseDir?
    if (!$baseDir || !$target || strncmp($target, $baseDir . DIRECTORY_SEPARATOR, strlen($baseDir) + 1) !== 0) {
        abort(404);
    }

    // Ausführen im Laravel-Kontext, Output abfangen und zurückgeben
    $cwd = getcwd();
    ob_start();
    try {
        chdir(dirname($target));     // falls das Skript relative Includes nutzt
        require $target;
        $content = ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        chdir($cwd);
        throw $e;                    // Laravel-Handler zeigt den Fehler
    } finally {
        @chdir($cwd);
    }

    return response($content);
});

// Debug route for JWT testing (remove in production)
if (app()->environment('local')) {
    Route::get('/debug/jwt', function (Request $request) {
        $authHeader = $request->header('Authorization');
        
        if (!$authHeader) {
            return response()->json(['error' => 'No Authorization header'], 401);
        }
        
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid Authorization header format'], 401);
        }
        
        $token = substr($authHeader, 7);
        
        try {
            $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key(file_get_contents(base_path(env('KEYCLOAK_PUBLIC_KEY_PATH'))), 'RS256'));
            $claims = (array)$decoded;
            
            return response()->json([
                'success' => true,
                'claims' => $claims,
                'roles' => $claims['resource_access']->flow->roles ?? [],
                'environment' => app()->environment(),
                'has_flow_tester' => in_array('flow-tester', $claims['resource_access']->flow->roles ?? [])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'JWT decode failed',
                'message' => $e->getMessage()
            ], 401);
        }
    });
}

