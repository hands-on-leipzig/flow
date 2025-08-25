<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ScheduleMatrixDb;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    public function roles(int $plan, ScheduleMatrixDb $builder)
    {
        $activities = $this->fetchActivities($plan, includeRooms: false);

        if ($activities->isEmpty()) {
            // Return stable headers so the frontend can render an empty grid
            return response()->json([
                'headers' => $builder->defaultRolesHeaders(),
                'rows'    => [],
            ]);
        }

        $matrix = $builder->buildRolesMatrix($activities);
        return response()->json($matrix);
    }

    public function teams(int $plan, ScheduleMatrixDb $builder)
    {
        $activities = $this->fetchActivities($plan, includeRooms: false);

        if ($activities->isEmpty()) {
            return response()->json([
                'headers' => $builder->defaultTeamsHeaders(),
                'rows'    => [],
            ]);
        }

        $matrix = $builder->buildTeamsMatrix($activities);
        return response()->json($matrix);
    }

    public function rooms(int $plan, ScheduleMatrixDb $builder)
    {
        $activities = $this->fetchActivities($plan, includeRooms: true);

        if ($activities->isEmpty()) {
            return response()->json([
                'headers' => $builder->defaultRoomsHeaders(),
                'rows'    => [],
            ]);
        }

        $matrix = $builder->buildRoomsMatrix($activities);
        return response()->json($matrix);
    }

    /**
     * Build the base activities query used by all views.
     *
     * @param int $plan
     * @param bool $includeRooms If true, joins/selects room type fields as well.
     * @return \Illuminate\Support\Collection
     */
    private function fetchActivities(int $plan, bool $includeRooms = false)
    {
        // Convert configured "free" constants from names to values defensively
        $freeIds = array_map(function ($c) {
            return (is_string($c) && defined($c)) ? constant($c) : $c;
        }, (array) config('atd.free'));

        $q = DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('m_activity_type_detail as atd', 'a.activity_type_detail', '=', 'atd.id')
            ->leftJoin('m_first_program as fp', 'atd.first_program', '=', 'fp.id')
            ->leftJoin('extra_block as peb', 'a.extra_block', '=', 'peb.id')
            ->where('ag.plan', $plan)
            ->whereNotIn('atd.id', $freeIds);

        // Optionally include room type join/fields (used by rooms view)
        if ($includeRooms) {
            $q->leftJoin('m_room_type as rt', 'a.room_type', '=', 'rt.id');
        }

        // Select the superset of fields needed across all views
        $select = '
            a.id as activity_id,
            a.start as start_time,
            a.`end` as end_time,
            COALESCE(peb.name, atd.name_preview) as activity_name,
            atd.id as activity_type_detail_id,
            fp.name as program_name,
            a.jury_lane as lane,
            a.jury_team as team,
            a.table_1 as table_1,
            a.table_1_team as table_1_team,
            a.table_2 as table_2,
            a.table_2_team as table_2_team
        ';

        if ($includeRooms) {
            // Add room fields only if requested
            $select .= ',
                a.room_type as room_type_id,
                rt.name as room_type_name,
                rt.sequence as room_type_sequence
            ';
        }

        return $q->orderBy('a.start')->selectRaw($select)->get();
    }
}