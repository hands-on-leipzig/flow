<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ScheduleMatrix;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    public function roles(int $plan, ScheduleMatrix $builder)
    {
        // Config enthält Konstanten-NAMEN (Strings) → hier in echte IDs wandeln
        $freeIds = array_map(function ($c) {
            return (is_string($c) && defined($c)) ? constant($c) : $c;
        }, config('atd.free'));

        $activities = DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('m_activity_type_detail as atd', 'a.activity_type_detail', '=', 'atd.id')
            ->leftJoin('m_first_program as fp', 'atd.first_program', '=', 'fp.id')
            ->leftJoin('extra_block as peb', 'a.extra_block', '=', 'peb.id')
            ->where('ag.plan', $plan)
            ->whereNotIn('atd.id', $freeIds)
            ->orderBy('a.start')
            ->selectRaw('
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
            ')
            ->get();

        if ($activities->isEmpty()) {
            return response()->json([
                'headers' => [
                    ['key'=>'time', 'title'=>'Zeit'],
                    ['key'=>'ex_be','title'=>'Ex Be'],
                    ['key'=>'ex_te','title'=>'Ex Te'],
                    ['key'=>'ex_gu','title'=>'Ex Gu'],
                    ['key'=>'ch_be','title'=>'Ch Be'],
                    ['key'=>'ch_te','title'=>'Ch Te'],
                    ['key'=>'ch_ju','title'=>'Ch Ju'],
                    ['key'=>'rg_sr','title'=>'RG SR'],
                    ['key'=>'rc_t1','title'=>'RC T1'],
                    ['key'=>'rc_t2','title'=>'RC T2'],
                    ['key'=>'rg_t1','title'=>'RG T1'],
                    ['key'=>'rg_t2','title'=>'RG T2'],
                ],
                'rows' => []
            ]);
        }

        $matrix = $builder->buildRolesMatrix($activities);
        return response()->json($matrix);
    }

    public function teams(int $plan, \App\Services\ScheduleMatrix $builder)
    {
        // dieselbe Query wie in roles()
        $freeIds = array_map(function ($c) {
            return (is_string($c) && defined($c)) ? constant($c) : $c;
        }, config('atd.free'));

        $activities = \DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('m_activity_type_detail as atd', 'a.activity_type_detail', '=', 'atd.id')
            ->leftJoin('m_first_program as fp', 'atd.first_program', '=', 'fp.id')
            ->leftJoin('extra_block as peb', 'a.extra_block', '=', 'peb.id')
            ->where('ag.plan', $plan)
            ->whereNotIn('atd.id', $freeIds)
            ->orderBy('a.start')
            ->selectRaw('
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
            ')
            ->get();

        $matrix = $builder->buildTeamsMatrix($activities);

        return response()->json($matrix);
    }
    
    public function rooms(int $plan, \App\Services\ScheduleMatrix $builder)
    {
        $freeIds = array_map(function ($c) {
            return (is_string($c) && defined($c)) ? constant($c) : $c;
        }, config('atd.free'));

        $activities = \DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('m_activity_type_detail as atd', 'a.activity_type_detail', '=', 'atd.id')
            ->leftJoin('m_first_program as fp', 'atd.first_program', '=', 'fp.id')
            ->leftJoin('extra_block as peb', 'a.extra_block', '=', 'peb.id')
            ->leftJoin('m_room_type as rt', 'a.room_type', '=', 'rt.id') // NEU: Raumtyp
            ->where('ag.plan', $plan)
            ->whereNotIn('atd.id', $freeIds)
            ->orderBy('a.start')
            ->selectRaw('
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
                a.table_2_team as table_2_team,
                a.room_type as room_type_id,
                rt.name as room_type_name,
                rt.sequence as room_type_sequence
            ')
            ->get();

        $matrix = $builder->buildRoomsMatrix($activities);

        return response()->json($matrix);
    }
}