<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ProgramCatalog;
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
                ->select(
                    'm_role.id',
                    'm_role.name',
                    'm_role.name_short',
                    'm_role.sequence',
                    'm_role.first_program',
                    'm_first_program.name as program',
                )
                ->orderByRaw($this->nullProgramLastSql('m_role.first_program'))
                ->orderByRaw(ProgramCatalog::sequenceOrderSql('m_role.first_program'))
                ->orderBy('m_role.sequence')
                ->get();

            return response()->json($roles);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Database error: '.$e->getMessage()], 500);
        }
    }

    /**
     * Get activity type categories for filter dropdown
     */
    public function getActivityTypeCategories()
    {
        try {
            $categories = DB::table('m_activity_type')
                ->select('id', 'name', 'first_program')
                ->orderByRaw($this->nullProgramLastSql('first_program'))
                ->orderByRaw(ProgramCatalog::sequenceOrderSql('first_program'))
                ->orderBy('sequence')
                ->get();

            return response()->json($categories);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Database error: '.$e->getMessage()], 500);
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
                ->select(
                    'm_activity_type_detail.id',
                    'm_activity_type_detail.name',
                    'm_activity_type_detail.activity_type',
                    'm_activity_type_detail.first_program',
                    'm_first_program.name as program',
                )
                ->orderByRaw($this->nullProgramLastSql('m_activity_type_detail.first_program'))
                ->orderByRaw(ProgramCatalog::sequenceOrderSql('m_activity_type_detail.first_program'))
                ->orderBy('m_activity_type_detail.sequence')
                ->get();

            return response()->json($activityTypes);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Database error: '.$e->getMessage()], 500);
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
            $rolesQuery = DB::table('m_role')
                ->leftJoin('m_first_program', 'm_role.first_program', '=', 'm_first_program.id')
                ->select(
                    'm_role.id',
                    'm_role.name',
                    'm_role.name_short',
                    'm_role.sequence',
                    'm_role.first_program',
                    'm_first_program.name as program',
                    'm_first_program.logo_stem',
                );
            if ($roleFilter !== 'all') {
                if ($roleFilter === 'null') {
                    $rolesQuery->whereNull('m_role.first_program');
                } elseif (is_numeric($roleFilter)) {
                    $rolesQuery->where('m_role.first_program', (int) $roleFilter);
                }
            }
            $roles = $rolesQuery
                ->orderByRaw($this->nullProgramLastSql('m_role.first_program'))
                ->orderByRaw(ProgramCatalog::sequenceOrderSql('m_role.first_program'))
                ->orderBy('m_role.sequence')
                ->get();

            $activitiesQuery = DB::table('m_activity_type_detail')
                ->leftJoin('m_first_program', 'm_activity_type_detail.first_program', '=', 'm_first_program.id')
                ->select(
                    'm_activity_type_detail.id',
                    'm_activity_type_detail.name',
                    'm_activity_type_detail.activity_type',
                    'm_activity_type_detail.sequence',
                    'm_activity_type_detail.first_program',
                    'm_first_program.name as program',
                    'm_first_program.logo_stem',
                );
            if ($activityFilter !== 'all') {
                $activitiesQuery->where('m_activity_type_detail.activity_type', $activityFilter);
            }
            $activities = $activitiesQuery
                ->orderByRaw($this->nullProgramLastSql('m_activity_type_detail.first_program'))
                ->orderByRaw(ProgramCatalog::sequenceOrderSql('m_activity_type_detail.first_program'))
                ->orderBy('m_activity_type_detail.sequence')
                ->get();

            $visibilityRules = DB::table('m_visibility')
                ->select('role', 'activity_type_detail')
                ->get()
                ->keyBy(function ($rule) {
                    return $rule->role.'_'.$rule->activity_type_detail;
                });

            $matrix = [];
            foreach ($roles as $role) {
                $row = [
                    'role' => $role,
                    'activities' => [],
                ];

                foreach ($activities as $activity) {
                    $key = $role->id.'_'.$activity->id;
                    $isVisible = $visibilityRules->has($key);

                    if ($visibilityFilter === 'visible' && ! $isVisible) {
                        continue;
                    }
                    if ($visibilityFilter === 'hidden' && $isVisible) {
                        continue;
                    }

                    $row['activities'][] = [
                        'activity' => $activity,
                        'visible' => $isVisible,
                    ];
                }

                $matrix[] = $row;
            }

            return response()->json([
                'roles' => $roles,
                'activities' => $activities,
                'matrix' => $matrix,
                'role_programs' => $this->distinctProgramsFrom('m_role', 'first_program'),
                'activity_type_programs' => $this->distinctProgramsFrom('m_activity_type', 'first_program'),
                'activity_types' => DB::table('m_activity_type')
                    ->select('id', 'name', 'first_program', 'sequence')
                    ->orderByRaw($this->nullProgramLastSql('first_program'))
                    ->orderByRaw(ProgramCatalog::sequenceOrderSql('first_program'))
                    ->orderBy('sequence')
                    ->get(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Database error: '.$e->getMessage()], 500);
        }
    }

    /**
     * Toggle visibility for a role-activity combination
     */
    public function toggleVisibility(Request $request)
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $validator = Validator::make($request->all(), [
            'role_id' => 'required|integer|exists:m_role,id',
            'activity_type_detail_id' => 'required|integer|exists:m_activity_type_detail,id',
            'visible' => 'required|boolean',
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
                DB::table('m_visibility')->insertOrIgnore([
                    'role' => $roleId,
                    'activity_type_detail' => $activityTypeDetailId,
                ]);
            } else {
                DB::table('m_visibility')
                    ->where('role', $roleId)
                    ->where('activity_type_detail', $activityTypeDetailId)
                    ->delete();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'visible' => $visible,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'Database error: '.$e->getMessage()], 500);
        }
    }

    /**
     * Bulk toggle visibility for multiple role-activity combinations
     */
    public function bulkToggle(Request $request)
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $validator = Validator::make($request->all(), [
            'toggles' => 'required|array',
            'toggles.*.role_id' => 'required|integer|exists:m_role,id',
            'toggles.*.activity_type_detail_id' => 'required|integer|exists:m_activity_type_detail,id',
            'toggles.*.visible' => 'required|boolean',
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
                'affected' => count($request->toggles),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'Database error'], 500);
        }
    }

    /**
     * Reorder m_role.sequence for one first_program (null = Overall).
     */
    public function reorderRoles(Request $request)
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $data = $request->validate([
            'first_program' => 'nullable|integer',
            'order' => 'required|array|min:1',
            'order.*.id' => 'required|integer',
            'order.*.sequence' => 'required|integer|min:1',
        ]);

        $firstProgram = array_key_exists('first_program', $data) ? $data['first_program'] : null;
        $payloadMap = collect($data['order'])
            ->map(fn ($row) => ['id' => (int) $row['id'], 'sequence' => (int) $row['sequence']])
            ->keyBy('id');

        $ids = $payloadMap->keys()->all();

        $rolesQuery = DB::table('m_role')->whereIn('id', $ids);
        if ($firstProgram === null) {
            $rolesQuery->whereNull('first_program');
        } else {
            $rolesQuery->where('first_program', (int) $firstProgram);
        }
        $current = $rolesQuery->pluck('sequence', 'id');

        if ($current->count() !== count($ids)) {
            return response()->json([
                'error' => 'One or more roles do not belong to the selected program',
            ], 422);
        }

        $changed = [];
        foreach ($payloadMap as $id => $row) {
            if ((int) $current[$id] !== (int) $row['sequence']) {
                $changed[$id] = (int) $row['sequence'];
            }
        }

        if ($changed === []) {
            return response()->json([
                'status' => 'ok',
                'updated' => 0,
                'skipped' => count($ids),
            ]);
        }

        DB::transaction(function () use ($changed) {
            foreach (array_chunk($changed, 800, true) as $chunk) {
                $ids = array_keys($chunk);
                $caseParts = [];
                foreach ($chunk as $id => $seq) {
                    $caseParts[] = "WHEN {$id} THEN {$seq}";
                }
                $caseSql = implode(' ', $caseParts);
                $idList = implode(',', $ids);

                DB::update("
                    UPDATE m_role
                    SET sequence = CASE id
                        {$caseSql}
                    END
                    WHERE id IN ({$idList})
                ");
            }
        });

        return response()->json([
            'status' => 'ok',
            'updated' => count($changed),
            'skipped' => count($ids) - count($changed),
        ]);
    }

    /**
     * @return list<array{id: int|null, name: string|null, display_name: string|null, letter: string|null, logo_stem: string|null, sequence: int|null}>
     */
    private function distinctProgramsFrom(string $table, string $column): array
    {
        $qualified = $table.'.'.$column;

        return DB::table($table)
            ->leftJoin('m_first_program', $qualified, '=', 'm_first_program.id')
            ->select(
                $qualified.' as id',
                'm_first_program.name',
                'm_first_program.display_name',
                'm_first_program.letter',
                'm_first_program.logo_stem',
                'm_first_program.sequence',
            )
            ->distinct()
            ->orderByRaw($this->nullProgramLastSql($qualified))
            ->orderByRaw(ProgramCatalog::sequenceOrderSql($qualified))
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id === null ? null : (int) $row->id,
                'name' => $row->name,
                'display_name' => $row->display_name,
                'letter' => $row->letter,
                'logo_stem' => $row->logo_stem,
                'sequence' => $row->sequence === null ? null : (int) $row->sequence,
            ])
            ->values()
            ->all();
    }

    private function nullProgramLastSql(string $column): string
    {
        return 'CASE WHEN '.$column.' IS NULL THEN 1 ELSE 0 END';
    }

    private function denyUnlessAdmin(Request $request): \Illuminate\Http\JsonResponse|null
    {
        $user = $request->user();
        if (! $user || ! $user->isFlowAdmin()) {
            return response()->json(['error' => 'Forbidden - admin role required'], 403);
        }

        return null;
    }
}
