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
        \Log::info('VisibilityController::getRoles called');
        
        try {
            $roles = DB::table('m_role')
                ->join('m_first_program', 'm_role.first_program', '=', 'm_first_program.id')
                ->select('m_role.id', 'm_role.name', 'm_first_program.name as program')
                ->orderBy('m_first_program.name')
                ->orderBy('m_role.name')
                ->get();

            \Log::info('Roles found:', ['count' => $roles->count(), 'roles' => $roles->toArray()]);
            return response()->json($roles);
        } catch (\Exception $e) {
            \Log::error('Error in getRoles:', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get all activity types for visibility matrix
     */
    public function getActivityTypes()
    {
        \Log::info('VisibilityController::getActivityTypes called');
        
        try {
            $activityTypes = DB::table('m_activity_type_detail')
                ->join('m_first_program', 'm_activity_type_detail.first_program', '=', 'm_first_program.id')
                ->select('m_activity_type_detail.id', 'm_activity_type_detail.name', 'm_first_program.name as program')
                ->orderBy('m_first_program.name')
                ->orderBy('m_activity_type_detail.name')
                ->get();

            \Log::info('Activity types found:', ['count' => $activityTypes->count(), 'types' => $activityTypes->toArray()]);
            return response()->json($activityTypes);
        } catch (\Exception $e) {
            \Log::error('Error in getActivityTypes:', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get visibility matrix data
     */
    public function getMatrix(Request $request)
    {
        \Log::info('VisibilityController::getMatrix called', [
            'role_filter' => $request->get('role_filter', 'all'),
            'activity_filter' => $request->get('activity_filter', 'all'),
            'visibility_filter' => $request->get('visibility_filter', 'all')
        ]);
        
        $roleFilter = $request->get('role_filter', 'all');
        $activityFilter = $request->get('activity_filter', 'all');
        $visibilityFilter = $request->get('visibility_filter', 'all');

        try {
            // Get roles with filtering
            $rolesQuery = DB::table('m_role')
                ->join('m_first_program', 'm_role.first_program', '=', 'm_first_program.id')
                ->select('m_role.id', 'm_role.name', 'm_first_program.name as program');
            if ($roleFilter !== 'all') {
                if ($roleFilter === 'challenge') {
                    $rolesQuery->where('m_first_program.name', 'CHALLENGE');
                } elseif ($roleFilter === 'explore') {
                    $rolesQuery->where('m_first_program.name', 'EXPLORE');
                } else {
                    $rolesQuery->where('m_role.id', $roleFilter);
                }
            }
            $roles = $rolesQuery->orderBy('m_first_program.name')->orderBy('m_role.name')->get();
            \Log::info('Roles query result:', ['count' => $roles->count()]);

            // Get activity types with filtering
            $activitiesQuery = DB::table('m_activity_type_detail')
                ->join('m_first_program', 'm_activity_type_detail.first_program', '=', 'm_first_program.id')
                ->select('m_activity_type_detail.id', 'm_activity_type_detail.name', 'm_first_program.name as program');
            if ($activityFilter !== 'all') {
                if ($activityFilter === 'challenge') {
                    $activitiesQuery->where('m_first_program.name', 'CHALLENGE');
                } elseif ($activityFilter === 'explore') {
                    $activitiesQuery->where('m_first_program.name', 'EXPLORE');
                } else {
                    $activitiesQuery->where('m_activity_type_detail.id', $activityFilter);
                }
            }
            $activities = $activitiesQuery->orderBy('m_first_program.name')->orderBy('m_activity_type_detail.name')->get();
            \Log::info('Activities query result:', ['count' => $activities->count()]);

            // Get existing visibility rules
            $visibilityRules = DB::table('m_visibility')
                ->select('role', 'activity_type_detail')
                ->get()
                ->keyBy(function ($rule) {
                    return $rule->role . '_' . $rule->activity_type_detail;
                });
            \Log::info('Visibility rules found:', ['count' => $visibilityRules->count()]);

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

            \Log::info('Matrix built successfully:', ['matrix_rows' => count($matrix)]);
            return response()->json([
                'roles' => $roles,
                'activities' => $activities,
                'matrix' => $matrix
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in getMatrix:', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Toggle visibility for a role-activity combination
     */
    public function toggleVisibility(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'role_id' => 'required|integer|exists:role,id',
            'activity_type_detail_id' => 'required|integer|exists:activity_type_detail,id',
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
                    'activity_type_detail' => $activityTypeDetailId,
                    'created_at' => now(),
                    'updated_at' => now()
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
            return response()->json(['error' => 'Database error'], 500);
        }
    }

    /**
     * Bulk toggle visibility for multiple role-activity combinations
     */
    public function bulkToggle(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'toggles' => 'required|array',
            'toggles.*.role_id' => 'required|integer|exists:role,id',
            'toggles.*.activity_type_detail_id' => 'required|integer|exists:activity_type_detail,id',
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
                        'activity_type_detail' => $toggle['activity_type_detail_id'],
                        'created_at' => now(),
                        'updated_at' => now()
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
