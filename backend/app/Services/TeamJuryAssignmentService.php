<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TeamJuryAssignmentService
{
    /**
     * Map plan slot (team_number_plan) => jury lane for a plan and programme.
     * Decoupled from DRAHT — keyed only on activity.jury_team (= plan slot).
     *
     * @return array<int, int> jury_team => jury_lane
     */
    public function assignmentsForProgram(int $planId, int $firstProgramId): array
    {
        $rows = DB::table('activity')
            ->join('activity_group', 'activity.activity_group', '=', 'activity_group.id')
            ->join('m_activity_type_detail', 'activity.activity_type_detail', '=', 'm_activity_type_detail.id')
            ->where('activity_group.plan', $planId)
            ->where('m_activity_type_detail.first_program', $firstProgramId)
            ->whereNotNull('activity.jury_team')
            ->whereNotNull('activity.jury_lane')
            ->groupBy('activity.jury_team')
            ->selectRaw('activity.jury_team as jury_team, MAX(activity.jury_lane) as jury_lane')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->jury_team] = (int) $row->jury_lane;
        }

        return $map;
    }
}
