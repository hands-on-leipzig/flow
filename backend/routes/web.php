<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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

