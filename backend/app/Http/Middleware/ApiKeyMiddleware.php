<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ApiKeyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Get API key from header
        $apiKey = $request->header('X-API-Key') 
               ?? $request->header('Authorization'); // Support: Bearer {key} or X-API-Key: {key}
        
        // Remove 'Bearer ' prefix if present
        if ($apiKey && str_starts_with($apiKey, 'Bearer ')) {
            $apiKey = substr($apiKey, 7);
        }
        
        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'error' => 'API key required',
                'message' => 'Please provide an API key in the X-API-Key header or Authorization header'
            ], 401);
        }
        
        // Hash the provided key and look it up
        $keyHash = hash('sha256', $apiKey);
        
        $keyRecord = ApiKey::where('key_hash', $keyHash)
            ->where('is_active', true)
            ->where(function($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->with('application')
            ->first();
        
        if (!$keyRecord) {
            Log::warning('Invalid API key attempt', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Invalid API key',
                'message' => 'The provided API key is invalid or expired'
            ], 401);
        }
        
        // Check if application is active
        if (!$keyRecord->application->is_active) {
            return response()->json([
                'success' => false,
                'error' => 'Application inactive',
                'message' => 'The application associated with this API key is inactive'
            ], 403);
        }
        
        // Check IP whitelist if configured
        if ($keyRecord->application->allowed_ips) {
            $clientIp = $request->ip();
            $allowedIps = is_array($keyRecord->application->allowed_ips) 
                ? $keyRecord->application->allowed_ips 
                : json_decode($keyRecord->application->allowed_ips, true);
            
            if (!in_array($clientIp, $allowedIps)) {
                Log::warning('API key used from unauthorized IP', [
                    'api_key_id' => $keyRecord->id,
                    'ip' => $clientIp,
                    'allowed_ips' => $allowedIps,
                ]);
                
                return response()->json([
                    'success' => false,
                    'error' => 'IP not allowed',
                    'message' => 'Your IP address is not authorized for this API key'
                ], 403);
            }
        }
        
        // Update last used timestamp
        $keyRecord->update(['last_used_at' => now()]);
        
        // Attach application context to request
        $request->merge([
            'api_key' => $keyRecord,
            'application' => $keyRecord->application,
        ]);
        
        return $next($request);
    }
}

