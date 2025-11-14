<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\RegionalPartner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserRegionalPartnerController extends Controller
{
    /**
     * Get all user-regional partner relations with user and regional partner details
     */
    public function index(): JsonResponse
    {
        try {
            $relations = DB::table('user_regional_partner as urp')
                ->join('user as u', 'urp.user', '=', 'u.id')
                ->join('regional_partner as rp', 'urp.regional_partner', '=', 'rp.id')
                ->select(
                    'urp.user as user_id',
                    'urp.regional_partner as regional_partner_id',
                    'u.subject as user_subject',
                    'u.name as user_name',
                    'u.email as user_email',
                    'rp.name as regional_partner_name',
                    'rp.region as regional_partner_region',
                    'rp.dolibarr_id as regional_partner_dolibarr_id'
                )
                ->orderBy('u.subject')
                ->orderBy('rp.name')
                ->get();

            // Group by user for better display
            $groupedRelations = $relations->groupBy('user_id')->map(function ($userRelations) {
                $firstRelation = $userRelations->first();
                return [
                    'user_id' => $firstRelation->user_id,
                    'user_subject' => $firstRelation->user_subject,
                    'user_name' => $firstRelation->user_name,
                    'user_email' => $firstRelation->user_email,
                    'regional_partners' => $userRelations->map(function ($relation) {
                        return [
                            'id' => $relation->regional_partner_id,
                            'name' => $relation->regional_partner_name,
                            'region' => $relation->regional_partner_region,
                            'dolibarr_id' => $relation->regional_partner_dolibarr_id
                        ];
                    })->toArray()
                ];
            })->values();

            return response()->json([
                'relations' => $groupedRelations,
                'total_users' => $groupedRelations->count(),
                'total_relations' => $relations->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch user-regional partner relations', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to fetch user-regional partner relations',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all users and regional partners for selection dropdowns
     */
    public function getSelectionData(): JsonResponse
    {
        try {
            $users = User::select('id', 'subject', 'name', 'email')
                ->orderBy('subject')
                ->get()
                ->map(function ($user) {
                    $displayParts = [];
                    if ($user->name) {
                        $displayParts[] = $user->name;
                    }
                    if ($user->email) {
                        $displayParts[] = "({$user->email})";
                    }
                    if ($user->subject) {
                        $displayParts[] = "[{$user->subject}]";
                    }
                    
                    $displayName = !empty($displayParts) 
                        ? implode(' ', $displayParts)
                        : "User {$user->id}";
                    
                    return [
                        'id' => $user->id,
                        'subject' => $user->subject,
                        'name' => $user->name,
                        'email' => $user->email,
                        'display_name' => $displayName
                    ];
                });

            $regionalPartners = RegionalPartner::select('id', 'name', 'region', 'dolibarr_id')
                ->orderBy('name')
                ->get()
                ->map(function ($partner) {
                    return [
                        'id' => $partner->id,
                        'name' => $partner->name,
                        'region' => $partner->region,
                        'dolibarr_id' => $partner->dolibarr_id,
                        'display_name' => "{$partner->name} ({$partner->region})"
                    ];
                });

            return response()->json([
                'users' => $users,
                'regional_partners' => $regionalPartners
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch selection data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to fetch selection data',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get statistics about user-regional partner relations
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = [
                'total_users' => User::count(),
                'total_regional_partners' => RegionalPartner::count(),
                'users_with_regional_partners' => DB::table('user_regional_partner')
                    ->distinct('user')
                    ->count(),
                'users_without_regional_partners' => User::count() - DB::table('user_regional_partner')
                    ->distinct('user')
                    ->count(),
                'average_regional_partners_per_user' => DB::table('user_regional_partner')
                    ->selectRaw('AVG(partner_count) as avg_partners')
                    ->fromSub(
                        DB::table('user_regional_partner')
                            ->selectRaw('user, COUNT(*) as partner_count')
                            ->groupBy('user'),
                        'user_partner_counts'
                    )
                    ->value('avg_partners') ?? 0,
                'most_common_regional_partners' => DB::table('user_regional_partner as urp')
                    ->join('regional_partner as rp', 'urp.regional_partner', '=', 'rp.id')
                    ->select('rp.id', 'rp.name', 'rp.region', DB::raw('COUNT(*) as user_count'))
                    ->groupBy('rp.id', 'rp.name', 'rp.region')
                    ->orderBy('user_count', 'desc')
                    ->limit(10)
                    ->get()
            ];

            return response()->json($stats);

        } catch (\Exception $e) {
            Log::error('Failed to fetch user-regional partner statistics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to fetch statistics',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add a user-regional partner relation
     */
    public function store(): JsonResponse
    {
        try {
            $validated = request()->validate([
                'user_id' => 'required|integer|exists:user,id',
                'regional_partner_id' => 'required|integer|exists:regional_partner,id'
            ]);

            // Check if relation already exists
            $existingRelation = DB::table('user_regional_partner')
                ->where('user', $validated['user_id'])
                ->where('regional_partner', $validated['regional_partner_id'])
                ->exists();

            if ($existingRelation) {
                return response()->json([
                    'error' => 'Relation already exists'
                ], 409);
            }

            DB::table('user_regional_partner')->insert([
                'user' => $validated['user_id'],
                'regional_partner' => $validated['regional_partner_id']
            ]);

            Log::info('User-regional partner relation created', [
                'user_id' => $validated['user_id'],
                'regional_partner_id' => $validated['regional_partner_id']
            ]);

            return response()->json([
                'message' => 'Relation created successfully'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create user-regional partner relation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to create relation',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove a user-regional partner relation
     */
    public function destroy(): JsonResponse
    {
        try {
            $validated = request()->validate([
                'user_id' => 'required|integer|exists:user,id',
                'regional_partner_id' => 'required|integer|exists:regional_partner,id'
            ]);

            $deleted = DB::table('user_regional_partner')
                ->where('user', $validated['user_id'])
                ->where('regional_partner', $validated['regional_partner_id'])
                ->delete();

            if ($deleted === 0) {
                return response()->json([
                    'error' => 'Relation not found'
                ], 404);
            }

            Log::info('User-regional partner relation deleted', [
                'user_id' => $validated['user_id'],
                'regional_partner_id' => $validated['regional_partner_id']
            ]);

            return response()->json([
                'message' => 'Relation deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete user-regional partner relation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to delete relation',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
