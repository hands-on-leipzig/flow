<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PreviewMatrixService;
use App\Services\ActivityFetcherService;
use Illuminate\Support\Facades\DB;


class PlanPreviewController extends Controller
{
    public function __construct(private ActivityFetcherService $activities) {}

    public function previewTeams(int $plan, PreviewMatrixService $builder)
    {
        // Team-Rollen ermitteln (nur für Programme, die in der Preview-Matrix relevant sind)
        $teamRoleIds = DB::table('m_role')
            ->whereNotNull('first_program')
            ->where('preview_matrix', 1)
            ->where('differentiation_parameter', 'team')
            ->pluck('id')
            ->all();

        // Activities gefiltert nach diesen Rollen laden
        $activities = $this->activities->fetchActivities(
            plan: $plan,
            roles: $teamRoleIds,
            freeBlocks: false
        );

        if ($activities->isEmpty()) {
            // Return stable headers so the frontend can render an empty grid
            return [
                ['key' => 'time', 'title' => 'Zeit'],
            ];
        }

        $matrix = $builder->buildTeamsMatrix($activities);
        return response()->json($matrix);
    }

    public function previewRooms(int $plan, PreviewMatrixService $builder)
    {
        $activities = $this->activities->fetchActivities(
            plan: $plan,
            includeRooms: true,
            freeBlocks: false
        );

        if ($activities->isEmpty()) {
            return [['key' => 'time', 'title' => 'Zeit']];
        }

        return response()->json($builder->buildRoomsMatrix($activities));
}

    public function previewRoles(int $plan, PreviewMatrixService $builder)
    {
        // Nur lane/table-Rollen für Preview
        $roles = DB::table('m_role')
            ->whereNotNull('first_program')
            ->where('preview_matrix', 1)
            ->whereIn('differentiation_parameter', ['lane','table'])
            ->orderBy('first_program')
            ->orderBy('sequence')
            ->get();

        $activities = $this->activities->fetchActivities(
            plan: $plan,
            roles: $roles->pluck('id')->all(),
            freeBlocks: false
        );

        if ($activities->isEmpty()) {
            return [ ['key' => 'time', 'title' => 'Zeit'] ];
        }

        $matrix = $builder->buildRolesMatrix($activities, $roles);
        return response()->json($matrix);
    }

    /**
     * Get Robot-Game match plan for preview
     */
    public function previewRobotGame(int $plan)
    {
        // Check if Challenge exists in this plan
        $hasChallenge = DB::table('activity')
            ->join('activity_group', 'activity.activity_group', '=', 'activity_group.id')
            ->join('m_activity_type_detail', 'activity.activity_type_detail', '=', 'm_activity_type_detail.id')
            ->where('activity_group.plan', $plan)
            ->where('m_activity_type_detail.first_program', 3)
            ->exists();

        if (!$hasChallenge) {
            return response()->json([
                'has_challenge' => false,
                'rounds' => []
            ]);
        }

        // Fetch all matches for this plan
        $matches = DB::table('match')
            ->where('plan', $plan)
            ->orderBy('round')
            ->orderBy('match_no')
            ->get();

        // Group by round
        $roundNames = [
            0 => 'Testrunde',
            1 => 'Runde 1',
            2 => 'Runde 2',
            3 => 'Runde 3',
        ];

        $rounds = [];
        foreach ([0, 1, 2, 3] as $roundNum) {
            $roundMatches = $matches->where('round', $roundNum)->sortBy('match_no')->values();
            
            if ($roundMatches->isEmpty()) {
                continue;
            }

            $rounds[] = [
                'round' => $roundNum,
                'name' => $roundNames[$roundNum],
                'matches' => $roundMatches->map(function ($match) {
                    return [
                        'match_id' => $match->id,
                        'match_no' => $match->match_no,
                        'table_1' => $match->table_1,
                        'table_1_team' => $match->table_1_team,
                        'table_2' => $match->table_2,
                        'table_2_team' => $match->table_2_team,
                    ];
                })->toArray()
            ];
        }

        return response()->json([
            'has_challenge' => true,
            'rounds' => $rounds
        ]);
    }

}