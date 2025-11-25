<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ApiRateLimitMiddleware
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
        $application = $request->get('application');
        
        if (!$application) {
            return $next($request);
        }
        
        $key = 'api_rate_limit:' . $application->id;
        $limit = $application->rate_limit ?? 1000; // Default 1000 requests per hour
        $window = 3600; // 1 hour in seconds
        
        $current = Cache::get($key, 0);
        
        if ($current >= $limit) {
            $resetAt = Cache::get($key . ':reset', now()->addHour()->timestamp);
            
            return response()->json([
                'success' => false,
                'error' => 'Rate limit exceeded',
                'message' => "You have exceeded the rate limit of {$limit} requests per hour",
                'limit' => $limit,
                'retry_after' => $resetAt - now()->timestamp,
            ], 429)->header('X-RateLimit-Limit', $limit)
              ->header('X-RateLimit-Remaining', 0)
              ->header('X-RateLimit-Reset', $resetAt);
        }
        
        // Increment counter
        Cache::put($key, $current + 1, now()->addSeconds($window));
        Cache::put($key . ':reset', now()->addSeconds($window)->timestamp, now()->addSeconds($window));
        
        $remaining = max(0, $limit - ($current + 1));
        
        $response = $next($request);
        
        // Add rate limit headers
        return $response->header('X-RateLimit-Limit', $limit)
                        ->header('X-RateLimit-Remaining', $remaining)
                        ->header('X-RateLimit-Reset', Cache::get($key . ':reset'));
    }
}

