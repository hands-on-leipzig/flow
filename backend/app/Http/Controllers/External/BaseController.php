<?php

namespace App\Http\Controllers\External;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

abstract class BaseController extends Controller
{
    /**
     * Get the application associated with the current request
     */
    protected function getApplication(Request $request)
    {
        return $request->get('application');
    }
    
    /**
     * Get the API key associated with the current request
     */
    protected function getApiKey(Request $request)
    {
        return $request->get('api_key');
    }
    
    /**
     * Check if the request has a specific scope
     */
    protected function hasScope(Request $request, string $scope): bool
    {
        $apiKey = $this->getApiKey($request);
        if (!$apiKey || !$apiKey->scopes) {
            return false;
        }
        
        $scopes = is_array($apiKey->scopes) 
            ? $apiKey->scopes 
            : json_decode($apiKey->scopes, true);
        
        return in_array($scope, $scopes ?? []);
    }
    
    /**
     * Require a specific scope, abort if not present
     */
    protected function requireScope(Request $request, string $scope)
    {
        if (!$this->hasScope($request, $scope)) {
            abort(403, "Scope '{$scope}' required for this operation");
        }
    }
    
    /**
     * Standard success response
     */
    protected function success($data = null, $message = null, $code = 200)
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
        ], $code);
    }
    
    /**
     * Standard error response
     */
    protected function error($message, $errors = null, $code = 400)
    {
        return response()->json([
            'success' => false,
            'error' => $message,
            'errors' => $errors,
            'timestamp' => now()->toIso8601String(),
        ], $code);
    }
}

