<?php

namespace App\Http\Controllers\Api;

use App\Enums\FirstProgram;
use App\Http\Controllers\Controller;
use App\Services\ActivityFetcherService;
use App\Services\PreviewMatrixService;
use App\Services\RolesPreviewGridService;
use App\Services\TeamsPreviewGridService;
use App\Support\PlanParameter;
use App\Support\ProgramPresence;
use Illuminate\Http\Request;
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
            'success' => true,
        ]);
    }

    /**
     * New Überblick-style roles grid (5-minute activity columns).
     * Old JSON matrix remains at previewRoles /roles.
     */
    public function previewRolesGrid(int $planId, RolesPreviewGridService $grid)
    {
        $data = $grid->build($planId);

        return response()->json([
            'html' => view('preview.roles-grid', [
                'programs' => $data['programs'],
                'eventsByDay' => $data['eventsByDay'],
            ])->render(),
            'programs' => array_map(static fn (array $p) => [
                'id' => $p['id'],
                'label' => $p['label'],
                'logo' => $p['logo'],
            ], $data['programs']),
            'success' => true,
        ]);
    }

    /**
     * New Überblick-style teams grid (5-minute columns, G/J/C/F cells).
     * Old JSON matrix remains at previewTeams /teams.
     */
    public function previewTeamsGrid(int $planId, TeamsPreviewGridService $grid)
    {
        $data = $grid->build($planId);

        return response()->json([
            'html' => view('preview.teams-grid', [
                'programs' => $data['programs'],
                'eventsByDay' => $data['eventsByDay'],
            ])->render(),
            'programs' => array_map(static fn (array $p) => [
                'id' => $p['id'],
                'label' => $p['label'],
                'logo' => $p['logo'],
            ], $data['programs']),
            'success' => true,
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
            ->whereIn('differentiation_parameter', ['lane', 'table'])
            ->orderBy('first_program')
            ->orderBy('sequence')
            ->get();

        $activities = $this->activities->fetchActivities(
            plan: $plan,
            roles: $roles->pluck('id')->all(),
            freeBlocks: false
        );

        if ($activities->isEmpty()) {
            return [['key' => 'time', 'title' => 'Zeit']];
        }

        $matrix = $builder->buildRolesMatrix($activities, $roles);

        return response()->json($matrix);
    }

    /**
     * Match-Plan preview for Challenge and/or Future 8+.
     * Query: first_program (3|8) — required when both are on; otherwise defaults to the only/lead program.
     */
    public function previewRobotGame(Request $request, int $plan)
    {
        $params = PlanParameter::load($plan);
        $presence = ProgramPresence::forPlan($plan, $params);
        $programs = $presence->challengeShapedOnIds();

        if ($programs === []) {
            return response()->json([
                'has_challenge' => false,
                'has_match_plan' => false,
                'programs' => [],
                'first_program' => null,
                'rounds' => [],
                'team_summary' => [],
            ]);
        }

        $requested = $request->query('first_program');
        if ($requested !== null && $requested !== '') {
            $firstProgram = (int) $requested;
            if (! in_array($firstProgram, $programs, true)) {
                return response()->json([
                    'message' => 'first_program is not on for this plan',
                    'programs' => $programs,
                ], 422);
            }
        } elseif (count($programs) === 1) {
            $firstProgram = $programs[0];
        } else {
            $firstProgram = $presence->leadProgramId() ?? $programs[0];
        }

        $isTwoDayEvent = (bool) $params->get('g_finale');

        $matches = DB::table('match')
            ->where('plan', $plan)
            ->where('first_program', $firstProgram)
            ->orderBy('round')
            ->orderBy('match_no')
            ->get();

        $rounds = [];
        if ($isTwoDayEvent) {
            $testRoundGroupCode = $firstProgram === FirstProgram::FUTURE_8->value
                ? 'f8_test_round'
                : 'r_test_round';
            $matchCode = $firstProgram === FirstProgram::FUTURE_8->value
                ? 'f8_r_match'
                : 'r_match';

            $rTestRoundGroupAtdId = DB::table('m_activity_type_detail')->where('code', $testRoundGroupCode)->value('id');
            $rMatchAtdId = DB::table('m_activity_type_detail')->where('code', $matchCode)->value('id');

            $testRoundActivities = DB::table('activity as a')
                ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
                ->where('ag.plan', $plan)
                ->where('ag.activity_type_detail', $rTestRoundGroupAtdId)
                ->where('a.activity_type_detail', $rMatchAtdId)
                ->orderBy('a.start')
                ->orderBy('a.id')
                ->get([
                    'a.id',
                    'a.activity_group',
                    'a.table_1',
                    'a.table_1_team',
                    'a.table_2',
                    'a.table_2_team',
                ]);

            $groupedTestRounds = $testRoundActivities->groupBy('activity_group')->values();
            foreach ($groupedTestRounds as $idx => $groupMatches) {
                $rounds[] = [
                    'round' => null,
                    'name' => 'Testrunde ' . ($idx + 1),
                    'matches' => $groupMatches->values()->map(function ($match, $matchIdx) {
                        return [
                            'match_id' => $match->id,
                            'match_no' => $matchIdx + 1,
                            'table_1' => $match->table_1,
                            'table_1_team' => $match->table_1_team,
                            'table_2' => $match->table_2,
                            'table_2_team' => $match->table_2_team,
                        ];
                    })->toArray(),
                ];
            }

            foreach ([1, 2, 3] as $roundNum) {
                $roundMatches = $matches->where('round', $roundNum)->sortBy('match_no')->values();
                if ($roundMatches->isEmpty()) {
                    continue;
                }

                $rounds[] = [
                    'round' => $roundNum,
                    'name' => 'Runde ' . $roundNum,
                    'matches' => $roundMatches->map(function ($match) {
                        return [
                            'match_id' => $match->id,
                            'match_no' => $match->match_no,
                            'table_1' => $match->table_1,
                            'table_1_team' => $match->table_1_team,
                            'table_2' => $match->table_2,
                            'table_2_team' => $match->table_2_team,
                        ];
                    })->toArray(),
                ];
            }
        } else {
            $roundNames = [
                0 => 'Testrunde',
                1 => 'Runde 1',
                2 => 'Runde 2',
                3 => 'Runde 3',
            ];

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
                    })->toArray(),
                ];
            }
        }

        $robotGameMatches = $matches->whereIn('round', [1, 2, 3]);

        $allTeams = collect();
        foreach ($robotGameMatches as $match) {
            if ($match->table_1_team > 0) {
                $allTeams->push($match->table_1_team);
            }
            if ($match->table_2_team > 0) {
                $allTeams->push($match->table_2_team);
            }
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
                $table = $match->table_1_team == $team ? $match->table_1 : $match->table_2;
                $tables[] = $table;

                $opponent = $match->table_1_team == $team ? $match->table_2_team : $match->table_1_team;
                if ($opponent > 0) {
                    $opponents[] = $opponent;
                }
            }

            $teamSummary[] = [
                'team' => $team,
                'different_tables' => count(array_unique($tables)),
                'different_opponents' => count(array_unique($opponents)),
            ];
        }

        return response()->json([
            'has_challenge' => in_array(FirstProgram::CHALLENGE->value, $programs, true),
            'has_match_plan' => true,
            'programs' => $programs,
            'first_program' => $firstProgram,
            'rounds' => $rounds,
            'team_summary' => $teamSummary,
        ]);
    }

    /**
     * Get raw activities for power-user debugging view
     */
    public function previewActivities(int $plan)
    {
        $user = request()->user();
        if (!$user || !$user->isFlowAdmin()) {
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

            if (! isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'activity_group_id' => $groupId,
                    'activity_group_name' => $activity->group_atd_name ?? 'Unknown Group',
                    'explore_group' => $activity->group_explore_group ?? null,
                    'activities' => [],
                ];
            }

            // Check if activity already exists (handle duplicates from room joins)
            if (! isset($groups[$groupId]['activities'][$activityId])) {
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
                    'slot_team' => $activity->slot_team !== null && $activity->slot_team !== '' ? (int) $activity->slot_team : null,
                    'room_type_name' => $activity->room_type_name ?? '',
                ];
            } else {
                // Update room info if current row has better room data
                if (empty($groups[$groupId]['activities'][$activityId]['room_type_name']) && ! empty($activity->room_type_name)) {
                    $groups[$groupId]['activities'][$activityId]['room_type_name'] = $activity->room_type_name;
                }
            }
        }

        // Convert associative activities arrays to indexed arrays
        foreach ($groups as &$group) {
            $group['activities'] = array_values($group['activities']);
        }

        return response()->json([
            'groups' => array_values($groups),
        ]);
    }
}
