<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PreviewMatrixService;
use App\Services\ActivityFetcherService;
use App\Http\Controllers\Api\PlanExportController;
use Illuminate\Support\Facades\DB;


class PlanPreviewController extends Controller
{
    public function __construct(
        private ActivityFetcherService $activities,
        private PlanExportController $planExport
    ) {}

    public function previewOverview(int $planId)
    {
        // Dynamically select all roles marked for preview matrix
        $previewRoles = DB::table('m_role')
            ->where('preview_matrix', 1)
            ->pluck('id')
            ->toArray();
            
        $data = $this->planExport->getEventOverviewData($planId, $previewRoles, false);
        
        // Return the data in the same format as other preview methods
        return response()->json([
            'html' => view('preview.event-overview', $data)->render(),
            'success' => true
        ]);
    }

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
                'rounds' => [],
                'team_summary' => []
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

        // Calculate team diversity metrics (Q2 and Q3)
        // Only use rounds 1-3 (robot game rounds, not test round)
        $robotGameMatches = $matches->whereIn('round', [1, 2, 3]);
        
        // Get all unique teams from matches
        $allTeams = collect();
        foreach ($robotGameMatches as $match) {
            if ($match->table_1_team > 0) $allTeams->push($match->table_1_team);
            if ($match->table_2_team > 0) $allTeams->push($match->table_2_team);
        }
        $uniqueTeams = $allTeams->unique()->sort()->values();
        
        $teamSummary = [];
        foreach ($uniqueTeams as $team) {
            $teamMatches = $robotGameMatches->filter(function ($match) use ($team) {
                return $match->table_1_team == $team || $match->table_2_team == $team;
            });

            $tables = [];
            $opponents = [];

            foreach ($teamMatches as $match) {
                // Get table this team played on
                $table = $match->table_1_team == $team ? $match->table_1 : $match->table_2;
                $tables[] = $table;

                // Get opponent (exclude Team 0 - volunteers)
                $opponent = $match->table_1_team == $team ? $match->table_2_team : $match->table_1_team;
                if ($opponent > 0) {
                    $opponents[] = $opponent;
                }
            }

            $teamSummary[] = [
                'team' => $team,
                'different_tables' => count(array_unique($tables)),
                'different_opponents' => count(array_unique($opponents))
            ];
        }

        return response()->json([
            'has_challenge' => true,
            'rounds' => $rounds,
            'team_summary' => $teamSummary
        ]);
    }

    /**
     * Get raw activities for power-user debugging view
     */
    public function previewActivities(int $plan)
    {
        // Check admin role (same as PlanActivityController)
        $jwt = request()->attributes->get('jwt');
        $roles = $jwt['resource_access']->flow->roles ?? [];

        if (!in_array('flow-admin', $roles) && !in_array('flow_admin', $roles)) {
            return response()->json(['error' => 'Forbidden - admin role required'], 403);
        }

        // Fetch all activities with comprehensive data
        $activities = $this->activities->fetchActivities(
            $plan,
            roles: [],                 // keine Rollen → alles selektieren
            includeRooms: true,        // Enable to get room data
            includeGroupMeta: true,    // Enable to get group names
            includeActivityMeta: false,
            includeTeamNames: false,
            freeBlocks: true
        );

        // Group by Activity Group (same logic as PlanActivityController)
        $groups = [];
        foreach ($activities as $activity) {
            $groupId = $activity->activity_group_id;
            $activityId = $activity->activity_id;
            
            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'activity_group_id' => $groupId,
                    'activity_group_name' => $activity->group_atd_name ?? 'Unknown Group',
                    'explore_group' => $activity->group_explore_group ?? null,
                    'activities' => []
                ];
            }
            
            // Check if activity already exists (handle duplicates from room joins)
            if (!isset($groups[$groupId]['activities'][$activityId])) {
                $groups[$groupId]['activities'][$activityId] = [
                    'activity_id' => $activity->activity_id,
                    'start_time' => $activity->start_time,
                    'end_time' => $activity->end_time,
                    'program' => $activity->program_name,
                    'activity_name' => $activity->activity_name,
                    'lane' => $activity->lane,
                    'team' => $activity->team,
                    'table_1_team' => $activity->table_1_team,
                    'table_2_team' => $activity->table_2_team,
                    'table_1' => $activity->table_1,
                    'table_2' => $activity->table_2,
                    'room_type_name' => $activity->room_type_name ?? '',
                ];
            } else {
                // Update room info if current row has better room data
                if (empty($groups[$groupId]['activities'][$activityId]['room_type_name']) && !empty($activity->room_type_name)) {
                    $groups[$groupId]['activities'][$activityId]['room_type_name'] = $activity->room_type_name;
                }
            }
        }

        // Convert associative activities arrays to indexed arrays
        foreach ($groups as &$group) {
            $group['activities'] = array_values($group['activities']);
        }
        
        return response()->json([
            'groups' => array_values($groups)
        ]);
    }

}