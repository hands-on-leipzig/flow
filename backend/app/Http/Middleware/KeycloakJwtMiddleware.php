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

            $roles = $claims['resource_access']->flow->roles ?? [];
            $env = App::environment();
            $path = $request->path();

            // Env-based role access - check BEFORE creating user
            if (in_array($env, ['local', 'staging'])) {
                if (!in_array('flow-tester', $roles)) {
                    return response()->json(['error' => 'Forbidden - tester role required'], 403);
                }
            } elseif ($env === 'production') {
                if (!in_array('regionalpartner', $roles) && !in_array('flow-admin', $roles)) {
                    return response()->json(['error' => 'Forbidden - partner or admin role required'], 403);
                }
            }

            try {
                $user = User::firstOrCreate(
                    ['subject' => $claims['sub'] ?? null],
                    [
                        'subject' => $claims['sub'] ?? null,
                        'password' => null, // No password needed for JWT authentication
                        'selection_event' => null,
                        'selection_regional_partner' => null,
                        'last_login' => now()
                    ]
                );

                // Update last_login timestamp for existing users
                if (!$user->wasRecentlyCreated) {
                    $user->update(['last_login' => now()]);
                }

                // Log user creation/authentication
                if ($user->wasRecentlyCreated) {
                    Log::info("New user created", [
                        'user_id' => $user->id,
                        'subject' => $user->subject,
                        'roles' => $roles,
                        'environment' => $env,
                        'last_login' => $user->last_login
                    ]);
                } else {
                    Log::debug("Existing user authenticated", [
                        'user_id' => $user->id,
                        'subject' => $user->subject,
                        'roles' => $roles,
                        'last_login' => $user->last_login
                    ]);
                }
            } catch (\Exception $e) {
                Log::error("Failed to create or retrieve user", [
                    'subject' => $claims['sub'] ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return response()->json(['error' => 'User authentication failed'], 500);
            }

            // Auto-assign regional partners for flow-tester role in test environment
            if (in_array($env, ['local', 'staging']) && in_array('flow-tester', $roles)) {
                $this->assignTestRegionalPartners($user);
            }

            Auth::login($user);
            Log::debug($path);
            Log::debug(str_starts_with($path, 'admin'));
            // Admin route restriction
            if (str_starts_with($path, 'api/admin') || str_starts_with($path, 'api/plans/activities/')) {
                if (!in_array('flow-admin', $roles) && !in_array('flow_admin', $roles)) {
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
        try {
            // Check if user already has regional partners assigned
            if ($user->regionalPartners()->count() > 0) {
                Log::debug("User {$user->subject} already has regional partners assigned");
                return; // User already has regional partners assigned
            }

            // Get test regional partners (created by fresh database script)
            $testRPs = DB::table('regional_partner')
                ->where('name', 'LIKE', 'Test Regional Partner%')
                ->get();

            if ($testRPs->count() == 0) {
                // No test regional partners found, create them
                Log::info("No test regional partners found, creating them for user: {$user->subject}");
                $this->createTestRegionalPartners();
                $testRPs = DB::table('regional_partner')
                    ->where('name', 'LIKE', 'Test Regional Partner%')
                    ->get();
            }

            // Assign user to all test regional partners
            $assignedCount = 0;
            foreach ($testRPs as $rp) {
                $result = DB::table('user_regional_partner')->insertOrIgnore([
                    'user' => $user->id,
                    'regional_partner' => $rp->id
                ]);
                if ($result) {
                    $assignedCount++;
                }
            }

            Log::info("Assigned {$assignedCount} test regional partners to flow-tester user: {$user->subject}");
        } catch (\Exception $e) {
            Log::error("Failed to assign test regional partners to user", [
                'user_id' => $user->id,
                'subject' => $user->subject,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create test regional partners and events if they don't exist
     */
    private function createTestRegionalPartners()
    {
        try {
            // This will be called if no test regional partners exist
            // We'll create minimal test data
            $latestSeason = DB::table('m_season')->orderBy('year', 'desc')->first();
            $level = DB::table('m_level')->first();

            if (!$latestSeason || !$level) {
                Log::warning("Cannot create test regional partners: missing season or level data", [
                    'has_season' => !!$latestSeason,
                    'has_level' => !!$level
                ]);
                return;
            }

            Log::info("Creating test regional partners and events", [
                'season' => $latestSeason->name,
                'level' => $level->name
            ]);

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
            $eventIds = DB::table('event')->insertGetId([
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

            Log::info("Created test regional partners and events", [
                'regional_partners' => 2,
                'events' => 3,
                'rp_a_id' => $rpAId,
                'rp_b_id' => $rpBId
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to create test regional partners and events", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
