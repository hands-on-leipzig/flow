<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

            // Auto-assign regional partners for flow-tester role in test environment
            if (in_array($env, ['local', 'staging']) && in_array('flow-tester', $roles)) {
                $this->assignTestRegionalPartners($user);
            }

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

    /**
     * Assign test regional partners to flow-tester users
     */
    private function assignTestRegionalPartners($user)
    {
        // Check if user already has regional partners assigned
        if ($user->regionalPartners()->count() > 0) {
            return; // User already has regional partners assigned
        }

        // Get test regional partners (created by fresh database script)
        $testRPs = DB::table('regional_partner')
            ->where('name', 'LIKE', 'Test Regional Partner%')
            ->get();

        if ($testRPs->count() == 0) {
            // No test regional partners found, create them
            $this->createTestRegionalPartners();
            $testRPs = DB::table('regional_partner')
                ->where('name', 'LIKE', 'Test Regional Partner%')
                ->get();
        }

        // Assign user to all test regional partners
        foreach ($testRPs as $rp) {
            DB::table('user_regional_partner')->insertOrIgnore([
                'user' => $user->id,
                'regional_partner' => $rp->id
            ]);
        }

        Log::info("Assigned test regional partners to flow-tester user: {$user->subject}");
    }

    /**
     * Create test regional partners and events if they don't exist
     */
    private function createTestRegionalPartners()
    {
        // This will be called if no test regional partners exist
        // We'll create minimal test data
        $latestSeason = DB::table('m_season')->orderBy('year', 'desc')->first();
        $level = DB::table('m_level')->first();

        if (!$latestSeason || !$level) {
            Log::warning("Cannot create test regional partners: missing season or level data");
            return;
        }

        // Create test regional partners
        $rpAId = DB::table('regional_partner')->insertGetId([
            'name' => 'Test Regional Partner A',
            'region' => 'Test Region A',
            'dolibarr_id' => 2001
        ]);

        $rpBId = DB::table('regional_partner')->insertGetId([
            'name' => 'Test Regional Partner B', 
            'region' => 'Test Region B',
            'dolibarr_id' => 2002
        ]);

        // Create test events
        DB::table('event')->insert([
            [
                'name' => 'Test Explore Event - Test Regional Partner A',
                'regional_partner' => $rpAId,
                'season' => $latestSeason->id,
                'level' => $level->id,
                'date' => now()->addDays(30),
                'days' => 1,
                'slug' => 'test-explore-event-a',
                'event_explore' => 1001,
                'event_challenge' => null
            ],
            [
                'name' => 'Test Challenge Event - Test Regional Partner A',
                'regional_partner' => $rpAId,
                'season' => $latestSeason->id,
                'level' => $level->id,
                'date' => now()->addDays(45),
                'days' => 1,
                'slug' => 'test-challenge-event-a',
                'event_explore' => null,
                'event_challenge' => 1002
            ],
            [
                'name' => 'Test Combined Event - Test Regional Partner B',
                'regional_partner' => $rpBId,
                'season' => $latestSeason->id,
                'level' => $level->id,
                'date' => now()->addDays(60),
                'days' => 1,
                'slug' => 'test-combined-event-b',
                'event_explore' => 1003,
                'event_challenge' => 1004
            ]
        ]);

        Log::info("Created test regional partners and events for flow-tester users");
    }
}
