<?php
// app/Services/ActivityFetcherService.php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ActivityFetcherService
{
    /**
     * Zentrale Activity-Query mit optionalen Joins/Filtern.
     * Signatur unverändert, damit bestehende Aufrufer minimal angepasst werden können.
     */
    public function fetchActivities(
        int $plan,
        array $roles = [],
        bool $includeRooms = false,
        bool $includeGroupMeta = false,
        bool $includeActivityMeta = false,
        bool $includeTeamNames = false,
        bool $freeBlocks = true,
        bool $include_past = false,
        array $rooms = [], // optionaler Filter: nur Aktivitäten in diesen Räumen (IDs), empty = alle Räume
        ) {

        $q = DB::table('activity as a')
            ->join('activity_group as ag', 'a.activity_group', '=', 'ag.id')
            ->join('m_activity_type_detail as atd', 'a.activity_type_detail', '=', 'atd.id')
            ->leftJoin('m_first_program as fp', 'atd.first_program', '=', 'fp.id')
            ->leftJoin('extra_block as peb', 'a.extra_block', '=', 'peb.id')
            ->join('plan as p', 'p.id', '=', 'ag.plan')
            ->join('event as e', 'e.id', '=', 'p.event')
            ->where('ag.plan', $plan);

        // Rollen-Filter (optional)
        if (!empty($roles)) {
            $q->whereIn('atd.id', function ($sub) use ($roles) {
                $sub->select('activity_type_detail')
                    ->from('m_visibility')
                    ->whereIn('role', $roles);
            });
        }

        // Free-Blocks filtern (optional)
        if (!$freeBlocks) {
            $q->where(function ($sub) {
                $sub->whereNull('a.extra_block')   // normale Activities
                    ->orWhereNotNull('peb.insert_point'); // Extra-Blocks mit insert_point
            });
        }

        // Filter: exclude activities outside event date range (default)
        if (!$include_past) {
            // Lower bound: activities must start on or after event start date
            $q->whereColumn('a.start', '>=', 'e.date');

            // Upper bound: activities must start on or before event end date
            // Event end date = e.date + (e.days - 1) days
            // Use DATE() to compare only the date part, ignoring time
            $q->whereRaw('DATE(a.start) <= DATE(e.date) + INTERVAL (COALESCE(e.days, 1) - 1) DAY');
        }

        // Group-Meta (optional)
        if ($includeGroupMeta) {
            $q->leftJoin('m_activity_type_detail as ag_atd', 'ag_atd.id', '=', 'ag.activity_type_detail')
            ->leftJoin('m_first_program as ag_fp', 'ag_fp.id', '=', 'ag_atd.first_program')
            ->leftJoin('m_activity_type as ag_at', 'ag_at.id', '=', 'ag_atd.activity_type');
        }

        // Rooms (optional)
        if ($includeRooms || !empty($rooms)) {
            $q->leftJoin('m_room_type as rt_room', 'a.room_type', '=', 'rt_room.id')
            ->leftJoin('room_type_room as rtr', function ($j) {
                $j->on('rtr.room_type', '=', 'a.room_type')
                    ->on('rtr.event', '=', 'p.event');
            })
            ->leftJoin('room as r', function ($j) {
                // (r.id = rtr.room AND r.event = p.event) OR (r.id = peb.room AND r.event = p.event)
                $j->on('r.id', '=', 'rtr.room')
                    ->on('r.event', '=', 'p.event')
                    ->orOn(function ($or) {
                        $or->on('r.id', '=', 'peb.room')
                        ->on('r.event', '=', 'p.event');
                    });
            });

            if (!empty($rooms)) {
                $q->where(function ($sub) use ($rooms) {
                    $sub->whereIn('r.id', $rooms)
                        ->orWhereIn('peb.room', $rooms);
                });
            }
        }

        // Team-Namen (optional): team_plan → team
        if ($includeTeamNames) {
            // Jury-Team
            $q->leftJoin('team_plan as tp_j', function($j) {
                $j->on('tp_j.plan', '=', 'p.id')
                ->on('tp_j.team_number_plan', '=', 'a.jury_team')
                ->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('team as tx')
                        ->whereColumn('tx.id', 'tp_j.team')
                        ->whereColumn('tx.event', 'p.event')
                        ->whereColumn('tx.first_program', 'atd.first_program');
                });
            })->leftJoin('team as t_j', function($j) {
                $j->on('t_j.id', '=', 'tp_j.team')
                ->on('t_j.event', '=', 'p.event')
                ->on('t_j.first_program', '=', 'atd.first_program');
            });

            // Table 1
            $q->leftJoin('team_plan as tp_t1', function($j) {
                $j->on('tp_t1.plan', '=', 'p.id')
                ->on('tp_t1.team_number_plan', '=', 'a.table_1_team')
                ->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('team as tx1')
                        ->whereColumn('tx1.id', 'tp_t1.team')
                        ->whereColumn('tx1.event', 'p.event')
                        ->whereColumn('tx1.first_program', 'atd.first_program');
                });
            })->leftJoin('team as t_t1', function($j) {
                $j->on('t_t1.id', '=', 'tp_t1.team')
                ->on('t_t1.event', '=', 'p.event')
                ->on('t_t1.first_program', '=', 'atd.first_program');
            });

            // Table 2
            $q->leftJoin('team_plan as tp_t2', function($j) {
                $j->on('tp_t2.plan', '=', 'p.id')
                ->on('tp_t2.team_number_plan', '=', 'a.table_2_team')
                ->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('team as tx2')
                        ->whereColumn('tx2.id', 'tp_t2.team')
                        ->whereColumn('tx2.event', 'p.event')
                        ->whereColumn('tx2.first_program', 'atd.first_program');
                });
            })->leftJoin('team as t_t2', function($j) {
                $j->on('t_t2.id', '=', 'tp_t2.team')
                ->on('t_t2.event', '=', 'p.event')
                ->on('t_t2.first_program', '=', 'atd.first_program');
            });
        }

        // Table-Names (Override aus table_event)
        $q->leftJoin('table_event as te1', function($j) {
            $j->on('te1.event', '=', 'p.event')
               ->where('te1.table_number', 1);
        });
        $q->leftJoin('table_event as te2', function($j) {
            $j->on('te2.event', '=', 'p.event')
               ->where('te2.table_number', 2);
        });
        $q->leftJoin('table_event as te3', function($j) {
            $j->on('te3.event', '=', 'p.event')
               ->where('te3.table_number', 3);
        });
        $q->leftJoin('table_event as te4', function($j) {
            $j->on('te4.event', '=', 'p.event')
               ->where('te4.table_number', 4);
        });

        // Basisselektion
        $select = '
            a.id as activity_id,
            ag.id as activity_group_id,
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
            a.extra_block as extra_block_id,
            peb.insert_point as extra_block_insert_point,
            CASE a.table_1
                WHEN 1 THEN CONCAT("Tisch ", COALESCE(te1.table_name, "1"))
                WHEN 3 THEN CONCAT("Tisch ", COALESCE(te3.table_name, "3"))
                ELSE NULL
            END AS table_1_name,
            CASE a.table_2
                WHEN 2 THEN CONCAT("Tisch ", COALESCE(te2.table_name, "2"))
                WHEN 4 THEN CONCAT("Tisch ", COALESCE(te4.table_name, "4"))
                ELSE NULL
            END AS table_2_name
        ';

        if ($includeRooms) {
            $select .= ',
                p.event as event_id,
                a.room_type as room_type_id,
                rt_room.name as room_type_name,
                rt_room.sequence as room_type_sequence,
                r.id as room_id,
                r.name as room_name,
                r.navigation_instruction as room_navigation,
                r.sequence as room_sequence,
                r.is_accessible as room_is_accessible
            ';
        }

        // --- Activity-Meta: bei Extra-Block Name/Description aus peb.* ziehen
        if ($includeActivityMeta) {
            $select .= ',
            CASE 
                WHEN a.extra_block IS NOT NULL THEN COALESCE(peb.name, atd.name)
                ELSE atd.name
            END                        as activity_atd_name,
                atd.code                   as activity_type_code,
                atd.first_program          as activity_first_program_id,
                fp.name                    as activity_first_program_name,
                CASE 
                    WHEN a.extra_block IS NOT NULL THEN COALESCE(peb.description, atd.description)
                    ELSE atd.description
                END                        as activity_description
            ';
        }

        // --- Group-Meta: ebenfalls bei Extra-Block Name/Description aus peb.* (auch wenn es formal Group-Meta ist)
        if ($includeGroupMeta) {
            $select .= ',
                ag.activity_type_detail    as activity_type_group,
                CASE 
                    WHEN a.extra_block IS NOT NULL THEN COALESCE(peb.name, ag_atd.name)
                    ELSE ag_atd.name
                END                        as group_atd_name,
                CASE 
                    WHEN a.extra_block IS NOT NULL THEN peb.first_program
                    ELSE ag_atd.first_program
                END                        as group_first_program_id,
                ag_fp.name                 as group_first_program_name,
                ag_at.overview_plan_column as group_overview_plan_column,
                ag_at.id                   as activity_type_id,
                ag_at.name                 as activity_type_name,
                ag_atd.code                as group_activity_type_code,
                a.extra_block as is_extra_block,
                ag.activity_type_detail as activity_type_detail,
                ag.explore_group           as group_explore_group,
                CASE 
                    WHEN a.extra_block IS NOT NULL THEN COALESCE(peb.description, ag_atd.description)
                    ELSE ag_atd.description
                END                        as group_description
            ';
        }

        if ($includeTeamNames) {
            $select .= ',
                t_j.id    as jury_team_id,
                t_j.name  as jury_team_name,
                t_j.team_number_hot  as jury_team_number_hot,
                COALESCE(tp_j.noshow, false) as jury_team_noshow,
                t_t1.id   as table_1_team_id,
                t_t1.name as table_1_team_name,
                t_t1.team_number_hot as table_1_team_number_hot,
                COALESCE(tp_t1.noshow, false) as table_1_team_noshow,
                t_t2.id   as table_2_team_id,
                t_t2.name as table_2_team_name,
                t_t2.team_number_hot as table_2_team_number_hot,
                COALESCE(tp_t2.noshow, false) as table_2_team_noshow
            ';
        }

        // vor dem finalen orderBy einbauen:
        $q->leftJoinSub(
            DB::table('activity')
            ->select('activity_group', DB::raw('MIN(start) as group_first_start'))
            ->groupBy('activity_group'),
            'ag_min',
            'ag_min.activity_group',
            '=',
            'ag.id'
        );

        // sortieren: erst Gruppenstart, dann Activity-Start
        $q->orderBy('ag_min.group_first_start')
        ->orderBy('a.start');

        return $q->selectRaw($select)->get();
    }

}