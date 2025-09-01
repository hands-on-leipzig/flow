<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class KeycloakJwtMiddleware
{
    protected string $jwksUrl = 'https://sso.hands-on-technology.org/realms/master/protocol/openid-connect/certs';
    protected string $expectedIssuer = 'https://sso.hands-on-technology.org/realms/master';
    protected string $expectedAudience = 'flow';

    public function handle(Request $request, Closure $next)
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $token = substr($authHeader, 7);
        $publicKeyPath = base_path(env('KEYCLOAK_PUBLIC_KEY_PATH'));

        if (!file_exists($publicKeyPath)) {
            Log::error("Public key file not found at $publicKeyPath");
            return response()->json(['error' => 'Server misconfiguration'], 500);
        }

        $publicKey = file_get_contents($publicKeyPath);

        try {
            $decoded = JWT::decode($token, new Key($publicKey, 'RS256'));
            $claims = (array)$decoded;

            if (($claims['iss'] ?? '') !== $this->expectedIssuer) {
                return response()->json(['error' => 'Invalid issuer'], 401);
            }

            if (!in_array($this->expectedAudience, (array)($claims['aud'] ?? []))) {
                return response()->json(['error' => 'Invalid audience'], 401);
            }

            $request->attributes->set('jwt', $claims);

            $user = User::firstOrCreate([
                'subject' => $claims['sub'] ?? null,
            ]);

            Auth::login($user);
            $roles = $claims['resource_access']->flow->roles ?? [];
            Log::debug($roles);
            $env = App::environment();
            $path = $request->path();

            // Env-based role access
            if (in_array($env, ['local', 'staging'])) {
                if (!in_array('flow-tester', $roles)) {
                    return response()->json(['error' => 'Forbidden - tester role required'], 403);
                }
            } elseif ($env === 'production') {
                if (!in_array('regionalpartner', $roles) && !in_array('flow-admin', $roles)) {
                    return response()->json(['error' => 'Forbidden - partner or admin role required'], 403);
                }
            }
            Log::debug($path);
            Log::debug(str_starts_with($path, 'admin'));
            // Admin route restriction
            if (str_starts_with($path, 'api/admin')) {
                if (!in_array('flow_admin', $roles)) {
                    return response()->json(['error' => 'Forbidden - admin role required'], 403);
                }
            }

        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid token', 'details' => $e->getMessage()], 401);
        }

        return $next($request);
    }
}
