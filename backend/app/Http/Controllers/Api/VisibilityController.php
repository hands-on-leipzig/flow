<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class VisibilityController extends Controller
{
    /**
     * Get all roles for visibility matrix
     */
    public function getRoles()
    {
        try {
            $roles = DB::table('m_role')
                ->leftJoin('m_first_program', 'm_role.first_program', '=', 'm_first_program.id')
                ->select('m_role.id', 'm_role.name', 'm_first_program.name as program')
                ->orderByRaw('CASE 
                    WHEN m_first_program.name = "EXPLORE" THEN 1 
                    WHEN m_first_program.name = "CHALLENGE" THEN 2 
                    WHEN m_first_program.name IS NULL THEN 3 
                    ELSE 4 
                END')
                ->orderBy('m_role.sequence')
                ->get();

            return response()->json($roles);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get activity type categories for filter dropdown
     */
    public function getActivityTypeCategories()
    {
        try {
            $categories = DB::table('m_activity_type')
                ->select('id', 'name')
                ->orderBy('id')
                ->get();

            return response()->json($categories);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get all activity types for visibility matrix
     */
    public function getActivityTypes()
    {
        try {
            $activityTypes = DB::table('m_activity_type_detail')
                ->leftJoin('m_first_program', 'm_activity_type_detail.first_program', '=', 'm_first_program.id')
                ->select('m_activity_type_detail.id', 'm_activity_type_detail.name', 'm_first_program.name as program')
                ->orderByRaw('CASE 
                    WHEN m_first_program.name = "EXPLORE" THEN 1 
                    WHEN m_first_program.name = "CHALLENGE" THEN 2 
                    WHEN m_first_program.name IS NULL THEN 3 
                    ELSE 4 
                END')
                ->orderBy('m_activity_type_detail.sequence')
                ->get();

            return response()->json($activityTypes);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get visibility matrix data
     */
    public function getMatrix(Request $request)
    {
        $roleFilter = $request->get('role_filter', 'all');
        $activityFilter = $request->get('activity_filter', 'all');
        $visibilityFilter = $request->get('visibility_filter', 'all');

        try {
            // Get roles with filtering
            $rolesQuery = DB::table('m_role')
                ->leftJoin('m_first_program', 'm_role.first_program', '=', 'm_first_program.id')
                ->select('m_role.id', 'm_role.name', 'm_first_program.name as program');
            if ($roleFilter !== 'all') {
                if ($roleFilter === '2') {
                    $rolesQuery->where('m_role.first_program', 2); // Explore
                } elseif ($roleFilter === '3') {
                    $rolesQuery->where('m_role.first_program', 3); // Challenge
                } elseif ($roleFilter === 'null') {
                    $rolesQuery->whereNull('m_role.first_program'); // Allgemein
                } else {
                    $rolesQuery->where('m_role.id', $roleFilter);
                }
            }
            $roles = $rolesQuery->orderByRaw('CASE 
                WHEN m_first_program.name = "EXPLORE" THEN 1 
                WHEN m_first_program.name = "CHALLENGE" THEN 2 
                WHEN m_first_program.name IS NULL THEN 3 
                ELSE 4 
            END')->orderBy('m_role.sequence')->get();

            // Get activity types with filtering
            $activitiesQuery = DB::table('m_activity_type_detail')
                ->leftJoin('m_first_program', 'm_activity_type_detail.first_program', '=', 'm_first_program.id')
                ->select('m_activity_type_detail.id', 'm_activity_type_detail.name', 'm_first_program.name as program');
            if ($activityFilter !== 'all') {
                $activitiesQuery->where('m_activity_type_detail.activity_type', $activityFilter);
            }
            $activities = $activitiesQuery->orderByRaw('CASE 
                WHEN m_first_program.name = "EXPLORE" THEN 1 
                WHEN m_first_program.name = "CHALLENGE" THEN 2 
                WHEN m_first_program.name IS NULL THEN 3 
                ELSE 4 
            END')->orderBy('m_activity_type_detail.sequence')->get();

            // Get existing visibility rules
            $visibilityRules = DB::table('m_visibility')
                ->select('role', 'activity_type_detail')
                ->get()
                ->keyBy(function ($rule) {
                    return $rule->role . '_' . $rule->activity_type_detail;
                });

            // Build matrix data
            $matrix = [];
            foreach ($roles as $role) {
                $row = [
                    'role' => $role,
                    'activities' => []
                ];
                
                foreach ($activities as $activity) {
                    $key = $role->id . '_' . $activity->id;
                    $isVisible = $visibilityRules->has($key);
                    
                    // Apply visibility filter
                    if ($visibilityFilter === 'visible' && !$isVisible) {
                        continue;
                    }
                    if ($visibilityFilter === 'hidden' && $isVisible) {
                        continue;
                    }
                    
                    $row['activities'][] = [
                        'activity' => $activity,
                        'visible' => $isVisible
                    ];
                }
                
                $matrix[] = $row;
            }

            return response()->json([
                'roles' => $roles,
                'activities' => $activities,
                'matrix' => $matrix
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Toggle visibility for a role-activity combination
     */
    public function toggleVisibility(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'role_id' => 'required|integer|exists:m_role,id',
            'activity_type_detail_id' => 'required|integer|exists:m_activity_type_detail,id',
            'visible' => 'required|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Invalid parameters'], 400);
        }

        $roleId = $request->role_id;
        $activityTypeDetailId = $request->activity_type_detail_id;
        $visible = $request->visible;

        try {
            DB::beginTransaction();

            if ($visible) {
                // Insert visibility rule
                DB::table('m_visibility')->insertOrIgnore([
                    'role' => $roleId,
                    'activity_type_detail' => $activityTypeDetailId
                ]);
            } else {
                // Remove visibility rule
                DB::table('m_visibility')
                    ->where('role', $roleId)
                    ->where('activity_type_detail', $activityTypeDetailId)
                    ->delete();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'visible' => $visible
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Bulk toggle visibility for multiple role-activity combinations
     */
    public function bulkToggle(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'toggles' => 'required|array',
            'toggles.*.role_id' => 'required|integer|exists:m_role,id',
            'toggles.*.activity_type_detail_id' => 'required|integer|exists:m_activity_type_detail,id',
            'toggles.*.visible' => 'required|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Invalid parameters'], 400);
        }

        try {
            DB::beginTransaction();

            foreach ($request->toggles as $toggle) {
                if ($toggle['visible']) {
                    DB::table('m_visibility')->insertOrIgnore([
                        'role' => $toggle['role_id'],
                        'activity_type_detail' => $toggle['activity_type_detail_id']
                    ]);
                } else {
                    DB::table('m_visibility')
                        ->where('role', $toggle['role_id'])
                        ->where('activity_type_detail', $toggle['activity_type_detail_id'])
                        ->delete();
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'affected' => count($request->toggles)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Database error'], 500);
        }
    }
}
