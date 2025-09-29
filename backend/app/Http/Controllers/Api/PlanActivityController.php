<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Services\ActivityFetcher;


class PlanActivityController extends Controller
{
    public function __construct(private ActivityFetcher $activities) {}

    public function activities(int $planId): \Illuminate\Http\JsonResponse
    {
        // TODO do that in a standardized way and also reflect it in routes
        // Check if user has admin role
        $jwt = request()->attributes->get('jwt');
        $roles = $jwt['resource_access']->flow->roles ?? [];

        if (!in_array('flow-admin', $roles) && !in_array('flow_admin', $roles)) {
            return response()->json(['error' => 'Forbidden - admin role required'], 403);
        }

        $rows = $this->activities->fetchActivities(
            $planId,
            roles: [],                 // keine Rollen → alles selektieren
            includeRooms: false,
            includeGroupMeta: false,
            includeActivityMeta: false,
            includeTeamNames: false,
            freeBlocks: true
        );

        // Nach Activity-Group gruppieren
        $groups = [];
        foreach ($rows as $row) {
            $gid = $row->activity_group_id ?? null;

            if (!isset($groups[$gid])) {
                $groups[$gid] = [
                    'activity_group_id' => $gid,
                    'activities' => [],
                ];
            }

            $groups[$gid]['activities'][] = [
                'activity_id'      => $row->activity_id,
                'start_time'       => $row->start_time,   // ISO/DB-Format; Frontend formatiert
                'end_time'         => $row->end_time,
                'program'          => $row->program_name, // z.B. CHALLENGE / EXPLORE (falls befüllt)
                'activity_name'    => $row->activity_name,
                'lane'             => $row->lane,
                'team'             => $row->team,
                'table_1'          => $row->table_1,
                'table_1_team'     => $row->table_1_team,
                'table_2'          => $row->table_2,
                'table_2_team'     => $row->table_2_team,
            ];
        }

        // Indexe bereinigen
        $groups = array_values($groups);

        return response()->json([
            'plan_id' => $planId,
            'groups'  => $groups,
        ]);
    }

public function actionNow(int $planId, Request $req): JsonResponse
    {
        [$pivot, $rows] = $this->prepareActivities($planId, $req);

        $rows = $rows->filter(function ($r) use ($pivot) {
            $start = Carbon::parse($r->start_time);
            $end   = Carbon::parse($r->end_time);

            return $start <= $pivot && $end >= $pivot;
        });

        return response()->json($this->groupActivitiesForApi($planId, $rows));
    }

    public function actionNext(int $planId, Request $req): JsonResponse
    {

        [$pivot, $rows] = $this->prepareActivities($planId, $req);

        $interval = (int) $req->query('interval', 30);

        $rows = $rows->filter(function ($r) use ($pivot, $interval) {
            $start = Carbon::parse($r->start_time);
            $end   = Carbon::parse($r->end_time);

            return $start >= $pivot && $start <= (clone $pivot)->addMinutes($interval);
        });

        return response()->json($this->groupActivitiesForApi($planId, $rows));
    }

    /**
     * Gemeinsame Selektion und Ausgabeform für now/next.
     */

    private function prepareActivities(int $planId, Request $req)
    {
        // Event-Datum holen
        $eventDate = DB::table('event')
            ->join('plan', 'plan.event', '=', 'event.id')
            ->where('plan.id', $planId)
            ->value('event.date');

        if (!$eventDate) {
            abort(404, 'Event not found');
        }

        // Uhrzeit aus Request holen
        $timeInput = $req->query('point_in_time'); // erwartet "HH:MM"
        if ($timeInput) {
            $pivot = Carbon::createFromFormat('Y-m-d H:i', $eventDate . ' ' . $timeInput, 'UTC');
        } else {
            $pivot = Carbon::createFromFormat('Y-m-d H:i', $eventDate . ' ' . now('Europe/Berlin')->format('H:i'), 'UTC');
        }

        // Erlaubte Rollen 14: Besucher Allgemein, 6: Besucher Challenge, 10: Besucher Explore
        $role = $req->query('role', 14);
        if (!is_numeric($role) || ((int)$role != 14 && (int)$role != 6 && (int)$role != 10)) {
            $role = 14; // Default: Publikum
        }

        $roles = [(int)$role];

        // Activities laden
        $rows = $this->activities->fetchActivities(
            $planId,
            $roles,                // Array mit genau 1 Rolle
            includeRooms: true,
            includeGroupMeta: true,
            includeActivityMeta: true,
            includeTeamNames: true,
            freeBlocks: true
        );

        return [$pivot, $rows];
    }

    private function groupActivitiesForApi(int $planId, $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $gid = $row->activity_group_id ?? null;

            if (!isset($groups[$gid])) {
                $groups[$gid] = [
                    'activity_group_id' => $gid,
                    'group_meta' => [
                        'name'               => $row->group_atd_name ?? null,
                        'first_program_id'   => $row->group_first_program_id ?? null,
                        'first_program_name' => $row->group_first_program_name ?? null,
                        'description'        => $row->group_description ?? null,
                    ],
                    'activities' => [],
                ];
            }

            // --- NEU: Activity-Key prüfen ---
            $aid = $row->activity_id;
            if (!isset($groups[$gid]['activities'][$aid])) {
                $groups[$gid]['activities'][$aid] = [
                    'activity_id'      => $row->activity_id,
                    'start_time'       => $row->start_time,
                    'end_time'         => $row->end_time,
                    'activity_name'    => $row->activity_name,
                    'meta' => [
                        'name'               => $row->activity_atd_name ?? null,
                        'first_program_id'   => $row->activity_first_program_id ?? null,
                        'first_program_name' => $row->activity_first_program_name ?? null,
                        'description'        => $row->activity_description ?? null,
                    ],
                    'program'          => $row->program_name,
                    'lane'             => $row->lane,
                    'team'             => $row->team,
                    'table_1'          => $row->table_1,
                    'table_1_name'     => $row->table_1_name ?? null,
                    'table_1_team'     => $row->table_1_team,
                    'table_2'          => $row->table_2,
                    'table_2_name'     => $row->table_2_name ?? null,
                    'table_2_team'     => $row->table_2_team,
                    'team_name'        => $row->jury_team_name ?? null,
                    'table_1_team_name'=> $row->table_1_team_name ?? null,
                    'table_2_team_name'=> $row->table_2_team_name ?? null,
                    'room' => [
                        'room_type_id'    => $row->room_type_id    ?? null,
                        'room_type_name'  => $row->room_type_name  ?? null,
                        'room_id'         => $row->room_id         ?? null,
                        'room_name'       => $row->room_name       ?? null,
                    ],
                ];
            } else {
                // --- NEU: Teamnamen ergänzen falls leer ---
                if (!$groups[$gid]['activities'][$aid]['table_1_team_name'] && $row->table_1_team_name) {
                    $groups[$gid]['activities'][$aid]['table_1_team_name'] = $row->table_1_team_name;
                }
                if (!$groups[$gid]['activities'][$aid]['table_2_team_name'] && $row->table_2_team_name) {
                    $groups[$gid]['activities'][$aid]['table_2_team_name'] = $row->table_2_team_name;
                }
                if (!$groups[$gid]['activities'][$aid]['team_name'] && $row->jury_team_name) {
                    $groups[$gid]['activities'][$aid]['team_name'] = $row->jury_team_name;
                }
            }
        }

    $result = [
        'plan_id' => $planId,
        'groups'  => array_values($groups),
    ];

    // Log: erster Group-Eintrag mit erster Activity
    if (!empty($result['groups'])) {
        $firstGroup = $result['groups'][0];
        $firstActivity = $firstGroup['activities'][0] ?? null;

        Log::info('groupActivitiesForApi first group', [
            'plan_id' => $planId,
            'group_id' => $firstGroup['activity_group_id'],
            'group_meta' => $firstGroup['group_meta'],
            'first_activity' => $firstActivity,
        ]);
    } else {
        Log::info('groupActivitiesForApi: no groups found', [
            'plan_id' => $planId,
        ]);
    }

    return $result;
    }
}

