<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\DrahtController;
use App\Http\Controllers\Api\PlanRoomTypeController;
use App\Models\MRole;
use App\Models\Plan;
use App\Models\Event;
use App\Services\ActivityFetcherService;
use App\Enums\FirstProgram;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PlanExportController extends Controller
{
    private ActivityFetcherService $activityFetcher;

    public function __construct(ActivityFetcherService $activityFetcher)
    {
        $this->activityFetcher = $activityFetcher;
    }

    /**
     * Get available roles for PDF export (lane/table roles with activities in plan)
     */
    public function availableRoles(int $eventId)
    {
        // Get plan for this event
        $plan = DB::table('plan')
            ->where('event', $eventId)
            ->select('id')
            ->first();

        if (!$plan) {
            return response()->json(['error' => 'Kein Plan zum Event gefunden'], 404);
        }

        $rolesWithActivities = $this->getRolesInPlan($plan->id);

        return response()->json(['roles' => $rolesWithActivities]);
    }

    /**
     * Get available programs for team PDF export (programs with teams in plan)
     */
    public function availableTeamPrograms(int $eventId)
    {
        // Get plan for this event
        $plan = DB::table('plan')
            ->where('event', $eventId)
            ->select('id')
            ->first();

        if (!$plan) {
            return response()->json(['error' => 'Kein Plan zum Event gefunden'], 404);
        }

        $programs = $this->getProgramsInPlan($plan->id);

        return response()->json(['programs' => $programs]);
    }

    /**
     * Helper: Get programs that have activities in a plan
     * @return array Array of program info with id and name
     */
    private function getProgramsInPlan(int $planId): array
    {
        $programs = [];

        // Check if Explore activities exist (first_program = 2)
        $hasExplore = DB::table('activity')
            ->join('activity_group', 'activity.activity_group', '=', 'activity_group.id')
            ->join('m_activity_type_detail', 'activity.activity_type_detail', '=', 'm_activity_type_detail.id')
            ->where('activity_group.plan', $planId)
            ->where('m_activity_type_detail.first_program', 2)
            ->exists();

        if ($hasExplore) {
            $programs[] = [
                'id' => 2,
                'name' => 'Explore',
            ];
        }

        // Check if Challenge activities exist (first_program = 3)
        $hasChallenge = DB::table('activity')
            ->join('activity_group', 'activity.activity_group', '=', 'activity_group.id')
            ->join('m_activity_type_detail', 'activity.activity_type_detail', '=', 'm_activity_type_detail.id')
            ->where('activity_group.plan', $planId)
            ->where('m_activity_type_detail.first_program', 3)
            ->exists();

        if ($hasChallenge) {
            $programs[] = [
                'id' => 3,
                'name' => 'Challenge',
            ];
        }

        return $programs;
    }

    /**
     * Helper: Get roles that have actual assignments in a plan
     * @return array Array of role info with id, name, first_program, differentiation_parameter
     */
    private function getRolesInPlan(int $planId): array
    {
        // Get all roles with pdf_export enabled and differentiation_parameter = lane or table
        $roles = MRole::where('pdf_export', true)
            ->whereIn('differentiation_parameter', ['lane', 'table'])
            ->orderBy('first_program')
            ->orderBy('sequence')
            ->get(['id', 'name', 'first_program', 'differentiation_parameter', 'sequence']);

        // Filter to only roles that have ASSIGNED activities in this plan
        $rolesWithActivities = [];
        foreach ($roles as $role) {
            // Check if there are activities with this role's lane/table actually set
            $hasAssignments = false;
            
            if ($role->differentiation_parameter === 'lane') {
                // Check if any activities have jury_lane set for this role
                $hasAssignments = DB::table('activity')
                    ->join('activity_group', 'activity.activity_group', '=', 'activity_group.id')
                    ->join('m_activity_type_detail', 'activity.activity_type_detail', '=', 'm_activity_type_detail.id')
                    ->join('m_visibility', 'm_activity_type_detail.id', '=', 'm_visibility.activity_type_detail')
                    ->where('activity_group.plan', $planId)
                    ->where('m_visibility.role', $role->id)
                    ->whereNotNull('activity.jury_lane')
                    ->exists();
            } elseif ($role->differentiation_parameter === 'table') {
                // Check if any activities have table_1 or table_2 set for this role
                $hasAssignments = DB::table('activity')
                    ->join('activity_group', 'activity.activity_group', '=', 'activity_group.id')
                    ->join('m_activity_type_detail', 'activity.activity_type_detail', '=', 'm_activity_type_detail.id')
                    ->join('m_visibility', 'm_activity_type_detail.id', '=', 'm_visibility.activity_type_detail')
                    ->where('activity_group.plan', $planId)
                    ->where('m_visibility.role', $role->id)
                    ->where(function($q) {
                        $q->whereNotNull('activity.table_1')
                          ->orWhereNotNull('activity.table_2');
                    })
                    ->exists();
            }

            if ($hasAssignments) {
                $rolesWithActivities[] = [
                    'id' => $role->id,
                    'name' => $role->name,
                    'first_program' => $role->first_program,
                    'differentiation_parameter' => $role->differentiation_parameter,
                ];
            }
        }

        return $rolesWithActivities;
    }

    public function download(string $type, int $eventId, Request $request)
    {
        // Plan zum Event finden
        $plan = DB::table('plan')
            ->where('event', $eventId)
            ->select('id', 'last_change')
            ->first();

        if (!$plan) {
            return response()->json(['error' => 'Kein Plan zum Event gefunden'], 404);
        }

        // Datum formatieren
        $formattedDate = $plan->last_change
            ? \Carbon\Carbon::parse($plan->last_change)
                ->timezone('Europe/Berlin')
                ->format('d.m.y')
            : '';

        // Determine rows per page depending on multiday events
        $eventDays = DB::table('event')->where('id', $eventId)->value('days');
        $isMultidayEvent = (int)($eventDays ?? 1) > 1;
        $maxRowsPerPage = $isMultidayEvent ? 14 : 16; // reduce when date bar is shown

        // PDF erzeugen
        $pdf = match ($type) {
            'rooms' => $this->roomSchedulePdf($plan->id, $maxRowsPerPage),
            'teams' => $this->teamSchedulePdf($plan->id, $request->input('program_ids', []), $maxRowsPerPage),
            'roles' => $this->roleSchedulePdf($plan->id, $request->input('role_ids', []), $maxRowsPerPage),
            'full'  => $this->fullSchedulePdf($plan->id),
            default => null,
        };

        if (!$pdf) {
            return response()->json(['error' => 'Unknown type'], 400);
        }

        // Dateiname abhängig vom Typ
        $names = [
            'rooms' => 'Räume',
            'teams' => 'Teams',
            'roles' => 'Rollen',
            'full'  => 'Gesamtplan',
        ];

        $name = $names[$type] ?? ucfirst($type);
        
        // Special handling for roles: add role names if subset selected
        if ($type === 'roles') {
            $selectedRoleIds = $request->input('role_ids', []);
            
            if (!empty($selectedRoleIds)) {
                // Get all available roles for THIS plan using helper method
                $rolesInPlan = $this->getRolesInPlan($plan->id);
                $roleIdsInPlan = array_column($rolesInPlan, 'id');
                
                // If not all roles (that are in plan) selected, append role names
                if (count($selectedRoleIds) < count($roleIdsInPlan)) {
                    $roleNames = MRole::whereIn('id', $selectedRoleIds)
                        ->orderBy('first_program')
                        ->orderBy('sequence')
                        ->pluck('name')
                        ->toArray();
                    
                    // Sanitize role names for filename (remove special chars)
                    $sanitizedNames = array_map(function($roleName) {
                        return preg_replace('/[^a-zA-Z0-9]/', '', $roleName);
                    }, $roleNames);
                    
                    $name = 'Rollen_' . implode('_', $sanitizedNames);
                }
            }
        }
        
        // Special handling for teams: add program name if only one selected
        if ($type === 'teams') {
            $selectedProgramIds = $request->input('program_ids', []);
            
            if (!empty($selectedProgramIds)) {
                // Get all available programs for THIS plan using helper method
                $programsInPlan = $this->getProgramsInPlan($plan->id);
                $programIdsInPlan = array_column($programsInPlan, 'id');
                
                // If not all programs (that are in plan) selected, append program name
                if (count($selectedProgramIds) < count($programIdsInPlan)) {
                    $programNames = [];
                    if (in_array(2, $selectedProgramIds)) {
                        $programNames[] = 'Explore';
                    }
                    if (in_array(3, $selectedProgramIds)) {
                        $programNames[] = 'Challenge';
                    }
                    
                    if (!empty($programNames)) {
                        $name = 'Teams_' . implode('_', $programNames);
                    }
                }
            }
        }
        
        $filename = "FLOW_{$name}_({$formattedDate}).pdf";

        // Umlaute transliterieren
        $filename = str_replace(
            ['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'],
            ['ae', 'oe', 'ue', 'Ae', 'Oe', 'Ue', 'ss'],
            $filename
        );

        // PDF zurückgeben mit Header für Dateiname
        return response($pdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('X-Filename', $filename)
            ->header('Access-Control-Expose-Headers', 'X-Filename');

    }


    public function fullSchedulePdf(int $planId)
    {
        Log::info("Starte PDF-Export für Plan $planId");

        $roles = MRole::where('pdf_export', true)
            ->orderBy('first_program')
            ->orderBy('sequence')
            ->get();

        $programGroups = [];
        $allFreeBlocks = collect();

        // First pass: collect all free blocks from all roles
        foreach ($roles as $role) {
            $activities = $this->activityFetcher->fetchActivities(
                plan: $planId,
                roles: [$role->id],
                includeRooms: true,
                includeGroupMeta: false,
                includeActivityMeta: true,
                includeTeamNames: true,
                freeBlocks: true
            );

            if ($activities->isEmpty()) {
                continue;
            }

            $activitiesCollection = collect($activities);
            $freeBlocks = $activitiesCollection->filter(function($a) {
                return !is_null($a->extra_block_id) && is_null($a->extra_block_insert_point);
            });

            $allFreeBlocks = $allFreeBlocks->concat($freeBlocks);
        }

        // Deduplicate free blocks by activity_id (same block appears in multiple roles)
        $allFreeBlocks = $allFreeBlocks->unique('activity_id');

        // Build one flat table for all free blocks
        if ($allFreeBlocks->isNotEmpty()) {
            $this->buildFreeBlocksTable($programGroups, $allFreeBlocks);
        }

        // Second pass: process regular activities by role
        foreach ($roles as $role) {
            $activities = $this->activityFetcher->fetchActivities(
                plan: $planId,
                roles: [$role->id],
                includeRooms: true,
                includeGroupMeta: false,
                includeActivityMeta: true,
                includeTeamNames: true,
                freeBlocks: true
            );

            if ($activities->isEmpty()) {
                continue;
            }

            // Filter to only regular activities (not free blocks)
            $activitiesCollection = collect($activities);
            $regularActivities = $activitiesCollection->filter(function($a) {
                return is_null($a->extra_block_id) || !is_null($a->extra_block_insert_point);
            });

            // Process regular activities under their program name
            if ($regularActivities->isNotEmpty()) {
                switch ($role->differentiation_parameter) {
                    case 'team':
                        $this->buildTeamBlock($programGroups, $regularActivities, $role, null, $planId);
                        break;

                    case 'lane':
                        $this->buildLaneBlock($programGroups, $regularActivities, $role, null, $planId);
                        break;

                    case 'table':
                        $this->buildTableBlock($programGroups, $regularActivities, $role);
                        break;

                    default:
                        $this->buildSimpleBlock($programGroups, $regularActivities, $role);
                        break;
                }
            }
        }

        if (empty($programGroups)) {
            return response()->json(['error' => 'Keine Aktivitäten gefunden'], 404);
        }

        // Sort program groups in desired order: Freie Blöcke, Explore, Challenge
        $sortOrder = [
            'Freie Blöcke' => 1,
            'FIRST LEGO League Explore' => 2,
            'FIRST LEGO League Challenge' => 3,
        ];
        
        uksort($programGroups, function($a, $b) use ($sortOrder) {
            $orderA = $sortOrder[$a] ?? 999;
            $orderB = $sortOrder[$b] ?? 999;
            return $orderA <=> $orderB;
        });

        // Plan + Event laden
        $plan = Plan::findOrFail($planId);
        $event = Event::findOrFail($plan->event);

        // Formatierungen
        $eventName = $event->name;
        $eventDate = Carbon::parse($event->date)->format('d.m.Y');
        $lastUpdated = Carbon::parse($plan->last_change, 'UTC')
            ->timezone('Europe/Berlin')
            ->format('d.m.Y H:i');

        $html = view('pdf.plan_export', [
            'programGroups' => $programGroups,
            'eventName'     => $eventName,
            'eventDate'     => $eventDate,
            'lastUpdated'   => $lastUpdated,
        ])->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        return $pdf;
    }

    /**
     * Block für Team-Differenzierung
     */
    private function buildTeamBlock(array &$programGroups, $activities, $role, $programNameOverride = null, $planId = null): void
    {
        // === Schritt 1: Activities entfalten (Lane/Table1/Table2 einzeln) ===
        $expanded   = collect();
        $neutral    = collect(); // neutrale Zeilen merken
        $teamInfo   = collect(); // team metadata: [team_num => [name, hot, id]]
        
        foreach ($activities as $a) {
            if (!empty($a->lane) && !empty($a->team)) {
                $clone = clone $a;
                $clone->team      = $a->team;
                $clone->team_name = $a->jury_team_name;
                $clone->team_number_hot = $a->jury_team_number_hot ?? null;
                $clone->team_id   = $a->jury_team_id ?? null;
                $clone->assign    = 'Jury ' . $a->lane;
                $expanded->push($clone);
                
                // Store team metadata
                if (!$teamInfo->has($a->team)) {
                    $teamInfo->put($a->team, [
                        'name' => $a->jury_team_name,
                        'hot'  => $a->jury_team_number_hot ?? null,
                        'id'   => $a->jury_team_id ?? null,
                    ]);
                }
            }
            if (!empty($a->table_1) && !empty($a->table_1_team)) {
                $clone = clone $a;
                $clone->team      = $a->table_1_team;
                $clone->team_name = $a->table_1_team_name;
                $clone->team_number_hot = $a->table_1_team_number_hot ?? null;
                $clone->team_id   = $a->table_1_team_id ?? null;
                $clone->assign    = $a->table_1_name ?? ('Tisch ' . $a->table_1);
                $expanded->push($clone);
                
                if (!$teamInfo->has($a->table_1_team)) {
                    $teamInfo->put($a->table_1_team, [
                        'name' => $a->table_1_team_name,
                        'hot'  => $a->table_1_team_number_hot ?? null,
                        'id'   => $a->table_1_team_id ?? null,
                    ]);
                }
            }
            if (!empty($a->table_2) && !empty($a->table_2_team)) {
                $clone = clone $a;
                $clone->team      = $a->table_2_team;
                $clone->team_name = $a->table_2_team_name;
                $clone->team_number_hot = $a->table_2_team_number_hot ?? null;
                $clone->team_id   = $a->table_2_team_id ?? null;
                $clone->assign    = $a->table_2_name ?? ('Tisch ' . $a->table_2);
                $expanded->push($clone);
                
                if (!$teamInfo->has($a->table_2_team)) {
                    $teamInfo->put($a->table_2_team, [
                        'name' => $a->table_2_team_name,
                        'hot'  => $a->table_2_team_number_hot ?? null,
                        'id'   => $a->table_2_team_id ?? null,
                    ]);
                }
            }

            // falls gar kein Team dran hängt → in neutral sammeln
            if (empty($a->lane) && empty($a->table_1) && empty($a->table_2)) {
                $clone = clone $a;
                $clone->team      = null;
                $clone->team_name = null;
                $clone->assign    = '–';
                $neutral->push($clone);
            }
        }

        // === Schritt 2: Nach Team gruppieren ===
        $groups = $expanded->groupBy('team');

        // === Schritt 3: Map-Funktion ===
        $mapRow = function ($a) {
            return [
                'start_hm' => Carbon::parse($a->start_time)->format('H:i'),
                'end_hm'   => Carbon::parse($a->end_time)->format('H:i'),
                'activity' => $this->formatActivityLabel($a),
                'is_free'  => $this->isFreeBlock($a),
                'assign'   => $a->assign,
                'room'     => $a->room_name ?? $a->room_type_name ?? '–',
                'team_id'  => $a->team,
                'team_name'=> $a->team_name,
            ];
        };

        // === Schritt 4: Room assignments (if planId available) ===
        $teamRooms = collect();
        if ($planId) {
            $roomData = DB::table('team_plan')
                ->join('room', 'team_plan.room', '=', 'room.id')
                ->where('team_plan.plan', $planId)
                ->select('team_plan.team', 'room.name as room_name')
                ->get()
                ->keyBy('team');
            
            $teamRooms = $roomData->pluck('room_name', 'team');
        }

        // === Schritt 5: Build team data with labels ===
        $teamData = [];
        foreach ($groups as $teamNum => $acts) {
            $info = $teamInfo->get($teamNum, ['name' => null, 'hot' => null, 'id' => null]);
            $teamName = $info['name'];
            $teamHot = $info['hot'];
            $teamId = $info['id'];
            
            // Build label: "TeamName (HotNumber) – Teambereich RoomName"
            $label = '';
            
            // Team name part
            if ($teamName && $teamHot) {
                $label = "{$teamName} ({$teamHot})";
            } elseif ($teamName) {
                $label = $teamName;
            } else {
                $label = sprintf("T%02d !Platzhalter, weil nicht genügend Teams angemeldet sind!", $teamNum);
            }
            
            // Room part
            $roomName = null;
            if ($teamId && $teamRooms->has($teamId)) {
                $roomName = $teamRooms->get($teamId);
            }
            
            if ($roomName) {
                $label .= " – Teambereich {$roomName}";
            } else {
                $label .= " – Teambereich !Platzhalter, weil das Team noch keinem Raum zugeordnet wurde!";
            }
            
            $allActs = $acts->concat($neutral)->sortBy('start_time');
            $firstAct = $allActs->first();
            $programName = $programNameOverride ?? ($firstAct->activity_first_program_name ?? 'Alles');
            
            $teamData[] = [
                'sortName' => $teamName ?? 'zzz', // For sorting
                'label' => $label,
                'programName' => $programName,
                'acts' => $allActs,
            ];
        }
        
        // === Schritt 6: Sort by team name and add to programGroups ===
        usort($teamData, function($a, $b) {
            return strcasecmp($a['sortName'], $b['sortName']);
        });
        
        foreach ($teamData as $td) {
            $programName = $td['programName'];
            
            if (!isset($programGroups[$programName])) {
                $programGroups[$programName] = [];
            }
            if (!isset($programGroups[$programName][$role->id])) {
                $programGroups[$programName][$role->id] = [
                    'role'  => $role->name,
                    'teams' => []
                ];
            }
            
            $programGroups[$programName][$role->id]['teams'][] = [
                'teamLabel' => $td['label'],
                'rows'      => $td['acts']->map($mapRow)->values()->all(),
            ];
        }
    }

    /**
     * Block für Lane-Differenzierung (Dummy)
     */

    private function buildLaneBlock(array &$programGroups, $activities, $role, $programNameOverride = null, $planId = null): void
    {
        // === Schritt 1: Activities entfalten (nur Lanes) ===
        $expanded = collect();
        $neutral  = collect();

        foreach ($activities as $a) {
            if (!empty($a->lane) && !empty($a->team)) {
                $clone = clone $a;
                $clone->lane      = $a->lane;
                $clone->team_id   = $a->team;
                $clone->team_name = $a->jury_team_name;
                $clone->team_number_hot = $a->jury_team_number_hot ?? null;
                $clone->assign    = 'Jury ' . $a->lane;
                $expanded->push($clone);
            }

            // Falls keine Lane → neutral
            if (empty($a->lane)) {
                $clone = clone $a;
                $clone->lane      = null;
                $clone->team_id   = null;
                $clone->team_name = null;
                $clone->team_number_hot = null;
                $clone->assign    = '–';
                $neutral->push($clone);
            }
        }

        // === Schritt 2: Gruppieren nach Lane ===
        $groups = $expanded->groupBy('lane');

        // === Schritt 3: Map-Funktion ===
        $mapRow = function ($a) {
            // Build team label: "TeamName (HotNumber)" or placeholder
            $teamLabel = '–';
            if ($a->team_id) {
                if ($a->team_name && $a->team_number_hot) {
                    $teamLabel = $a->team_name . ' (' . $a->team_number_hot . ')';
                } elseif ($a->team_name) {
                    $teamLabel = $a->team_name;
                } else {
                    $teamLabel = sprintf("T%02d !Platzhalter, weil nicht genügend Teams angemeldet sind!", $a->team_id);
                }
            }

            return [
                'start_hm' => Carbon::parse($a->start_time)->format('H:i'),
                'end_hm'   => Carbon::parse($a->end_time)->format('H:i'),
                'activity' => $this->formatActivityLabel($a),
                'assign'   => $a->assign, // Jury X
                'room'     => $a->room_name ?? $a->room_type_name ?? '–',
                'team'     => $teamLabel,
            ];
        };

        // === Schritt 4: Room assignments (if planId available) ===
        $laneRooms = collect();
        if ($planId) {
            $eventId = DB::table('plan')->where('id', $planId)->value('event');
            if ($eventId) {
                // Get the program for this role from the role's activities
                $programId = null;
                foreach ($activities as $act) {
                    if (!empty($act->activity_first_program_id)) {
                        $programId = $act->activity_first_program_id;
                        break;
                    }
                }
                
                if ($programId) {
                    // Map program to room type IDs
                    $roomTypeIds = [];
                    if ($programId == 2) { // Explore (ID 2)
                        $roomTypeIds = [8, 9, 10, 11, 12]; // Begutachtung 1-5
                    } elseif ($programId == 3) { // Challenge (ID 3)
                        $roomTypeIds = [2, 3, 4, 5, 6]; // Jurybewertung 1-5
                    }
                    
                    if (!empty($roomTypeIds)) {
                        $roomData = DB::table('room_type_room as rtr')
                            ->join('room as r', 'rtr.room', '=', 'r.id')
                            ->join('m_room_type as mrt', 'rtr.room_type', '=', 'mrt.id')
                            ->where('rtr.event', $eventId)
                            ->whereIn('mrt.id', $roomTypeIds)
                            ->select('mrt.id as room_type_id', 'r.name as room_name')
                            ->get();
                        
                        // Map room_type_id to lane number
                        foreach ($roomData as $rd) {
                            if ($programId == 2) { // Explore: Begutachtung 1 (ID 8) = lane 1
                                $laneNumber = $rd->room_type_id - 7; // Begutachtung 1 (ID 8) = lane 1
                            } else { // Challenge: Jurybewertung 1 (ID 2) = lane 1
                                $laneNumber = $rd->room_type_id - 1; // Jurybewertung 1 (ID 2) = lane 1
                            }
                            $laneRooms->put($laneNumber, $rd->room_name);
                        }
                    }
                }
            }
        }

        // === Schritt 5: Iteration über Lanes ===
        foreach ($groups->sortKeys() as $laneId => $acts) {
            $allActs     = $acts->concat($neutral)->sortBy('start_time');
            $firstAct    = $allActs->first();
            $programName = $programNameOverride ?? ($firstAct->activity_first_program_name ?? 'Alles');

            if (!isset($programGroups[$programName])) {
                $programGroups[$programName] = [];
            }
            if (!isset($programGroups[$programName][$role->id])) {
                $programGroups[$programName][$role->id] = [
                    'role'  => $role->name,
                    'lanes' => []
                ];
            }

            // Build label: "Gruppe X - Raum RoomName"
            $juryLabel = 'Gruppe ' . $laneId;
            
            $roomName = $laneRooms->get($laneId);
            if ($roomName) {
                $juryLabel .= ' – Raum ' . $roomName;
            } else {
                $juryLabel .= ' – Raum !Platzhalter, weil die Gruppe noch keinem Raum zugeordnet wurde!';
            }

            $programGroups[$programName][$role->id]['lanes'][] = [
                'juryLabel' => $juryLabel,
                'rows'      => $allActs->map($mapRow)->values()->all(),
            ];
        }
    }

    /**
     * Block für Table-Differenzierung
     */
    private function buildTableBlock(array &$programGroups, $activities, $role, $programNameOverride = null): void
    {
        $expanded = collect();

        // Schritt 1: Activities entfalten (Table_1 und Table_2 separat)
        foreach ($activities as $a) {
            if (!empty($a->table_1) && !empty($a->table_1_team)) {
                $clone = clone $a;
                $clone->table_id   = $a->table_1;
                $clone->team_id    = $a->table_1_team;
                $clone->team_name  = $a->table_1_team_name;
                $clone->team_number_hot = $a->table_1_team_number_hot ?? null;
                $clone->assign     = $a->table_1_name ?? ('Tisch ' . $a->table_1);
                $expanded->push($clone);
            }
            if (!empty($a->table_2) && !empty($a->table_2_team)) {
                $clone = clone $a;
                $clone->table_id   = $a->table_2;
                $clone->team_id    = $a->table_2_team;
                $clone->team_name  = $a->table_2_team_name;
                $clone->team_number_hot = $a->table_2_team_number_hot ?? null;
                $clone->assign     = $a->table_2_name ?? ('Tisch ' . $a->table_2);
                $expanded->push($clone);
            }
        }

        if ($expanded->isEmpty()) {
            return;
        }

        // Schritt 2: Gruppieren nach Table-ID
        $groups = $expanded->groupBy('table_id');

        // Schritt 3: Map-Funktion für Rows
        $mapRow = function ($a) {
            // Build team label: "TeamName (HotNumber)" or placeholder
            $teamLabel = '–';
            if ($a->team_id) {
                if ($a->team_name && $a->team_number_hot) {
                    $teamLabel = $a->team_name . ' (' . $a->team_number_hot . ')';
                } elseif ($a->team_name) {
                    $teamLabel = $a->team_name;
                } else {
                    $teamLabel = sprintf("T%02d !Platzhalter, weil nicht genügend Teams angemeldet sind!", $a->team_id);
                }
            }

            return [
                'start_hm'  => Carbon::parse($a->start_time)->format('H:i'),
                'end_hm'    => Carbon::parse($a->end_time)->format('H:i'),
                'activity'  => $this->formatActivityLabel($a),
                'is_free'   => $this->isFreeBlock($a),
                'teamLabel' => $teamLabel,
                'assign'    => $a->assign,
                'room'      => $a->room_name ?? $a->room_type_name ?? '–',
            ];
        };

        // Schritt 4: In ProgramGroups einsortieren
        foreach ($groups->sortKeys() as $tableId => $acts) {
            $acts = $acts->sortBy('start_time');
            $firstAct = $acts->first();
            $programName = $programNameOverride ?? ($firstAct->activity_first_program_name ?? 'Alles');

            // Get custom table name from first activity (already stored in assign field)
            $tableLabel = $firstAct->assign ?? ('Tisch ' . $tableId);

            if (!isset($programGroups[$programName])) {
                $programGroups[$programName] = [];
            }
            if (!isset($programGroups[$programName][$role->id])) {
                $programGroups[$programName][$role->id] = [
                    'role'   => $role->name,
                    'tables' => []
                ];
            }

            $programGroups[$programName][$role->id]['tables'][] = [
                'tableLabel' => $tableLabel,
                'rows'       => $acts->map($mapRow)->values()->all(),
            ];
        }
    }

    /**
     * Block für Rollen ohne Differenzierung
     */
    private function buildSimpleBlock(array &$programGroups, $activities, $role, $programNameOverride = null): void
    {
        if ($activities->isEmpty()) {
            return;
        }

        // Map-Funktion für Rows
        $mapRow = function ($a) {
            // Teamlabel bestimmen (falls es über Jury/Tables erkennbar ist)
            $teamLabel = '–';
            
            // Check jury team first
            if (!empty($a->jury_team_name)) {
                if ($a->jury_team_number_hot) {
                    $teamLabel = $a->jury_team_name . ' (' . $a->jury_team_number_hot . ')';
                } else {
                    $teamLabel = $a->jury_team_name;
                }
            } elseif (!empty($a->table_1_team_name)) {
                if ($a->table_1_team_number_hot) {
                    $teamLabel = $a->table_1_team_name . ' (' . $a->table_1_team_number_hot . ')';
                } else {
                    $teamLabel = $a->table_1_team_name;
                }
            } elseif (!empty($a->table_2_team_name)) {
                if ($a->table_2_team_number_hot) {
                    $teamLabel = $a->table_2_team_name . ' (' . $a->table_2_team_number_hot . ')';
                } else {
                    $teamLabel = $a->table_2_team_name;
                }
            } elseif (!empty($a->team)) {
                $teamLabel = sprintf("T%02d !Platzhalter, weil nicht genügend Teams angemeldet sind!", $a->team);
            }

            // Assignment (Jury/Tisch/-)
            $assign = '–';
            if (!empty($a->lane)) {
                $assign = 'Jury ' . $a->lane;
            } elseif (!empty($a->table_1)) {
                $assign = $a->table_1_name ?? ('Tisch ' . $a->table_1);
            } elseif (!empty($a->table_2)) {
                $assign = $a->table_2_name ?? ('Tisch ' . $a->table_2);
            }

            return [
                'start_hm'  => \Carbon\Carbon::parse($a->start_time)->format('H:i'),
                'end_hm'    => \Carbon\Carbon::parse($a->end_time)->format('H:i'),
                'activity'  => $this->formatActivityLabel($a),
                'teamLabel' => $teamLabel ?? '–',
                'assign'    => $assign,
                'room'      => $a->room_name ?? $a->room_type_name ?? '–',
            ];
        };

        $acts = $activities->sortBy('start_time');
        $firstAct = $acts->first();
        $programName = $programNameOverride ?? ($firstAct->activity_first_program_name ?? 'Alles');

        if (!isset($programGroups[$programName])) {
            $programGroups[$programName] = [];
        }
        if (!isset($programGroups[$programName][$role->id])) {
            $programGroups[$programName][$role->id] = [
                'role'    => $role->name,
                'general' => []   // kein team/lane/table → nur eine Liste
            ];
        }

        $programGroups[$programName][$role->id]['general'][] = [
            'rows' => $acts->map($mapRow)->values()->all(),
        ];
    }

    /**
     * Build a single flat table for all free blocks (no role sub-sections)
     */
    private function buildFreeBlocksTable(array &$programGroups, $activities): void
    {
        if ($activities->isEmpty()) {
            return;
        }

        // Map function for rows
        $mapRow = function ($a) {
            // Teamlabel bestimmen
            $teamLabel = '–';
            
            // Check jury team first
            if (!empty($a->jury_team_name)) {
                if ($a->jury_team_number_hot) {
                    $teamLabel = $a->jury_team_name . ' (' . $a->jury_team_number_hot . ')';
                } else {
                    $teamLabel = $a->jury_team_name;
                }
            } elseif (!empty($a->table_1_team_name)) {
                if ($a->table_1_team_number_hot) {
                    $teamLabel = $a->table_1_team_name . ' (' . $a->table_1_team_number_hot . ')';
                } else {
                    $teamLabel = $a->table_1_team_name;
                }
            } elseif (!empty($a->table_2_team_name)) {
                if ($a->table_2_team_number_hot) {
                    $teamLabel = $a->table_2_team_name . ' (' . $a->table_2_team_number_hot . ')';
                } else {
                    $teamLabel = $a->table_2_team_name;
                }
            } elseif (!empty($a->team)) {
                $teamLabel = sprintf("T%02d !Platzhalter, weil nicht genügend Teams angemeldet sind!", $a->team);
            }

            // Assignment (Jury/Tisch/-)
            $assign = '–';
            if (!empty($a->lane)) {
                $assign = 'Jury ' . $a->lane;
            } elseif (!empty($a->table_1)) {
                $assign = $a->table_1_name ?? ('Tisch ' . $a->table_1);
            } elseif (!empty($a->table_2)) {
                $assign = $a->table_2_name ?? ('Tisch ' . $a->table_2);
            }

            return [
                'start_hm'  => \Carbon\Carbon::parse($a->start_time)->format('H:i'),
                'end_hm'    => \Carbon\Carbon::parse($a->end_time)->format('H:i'),
                'activity'  => $this->formatActivityLabel($a),
                'teamLabel' => $teamLabel ?? '–',
                'assign'    => $assign,
                'room'      => $a->room_name ?? $a->room_type_name ?? '–',
            ];
        };

        $acts = $activities->sortBy('start_time');
        
        $programName = 'Freie Blöcke';

        // Create single entry with dummy role ID 0
        $programGroups[$programName] = [
            0 => [
                'role'    => null,  // No role header
                'general' => [
                    ['rows' => $acts->map($mapRow)->values()->all()]
                ]
            ]
        ];
    }


    public function roomSchedulePdf(int $planId, $maxRowsPerPage = 16)
    {
        $activities = app(\App\Services\ActivityFetcherService::class)
            ->fetchActivities(
                $planId,
                [6, 10, 14],   // Rollen: Publikum E, C und generisch
                true,          // includeRooms
                true,          // includeGroupMeta
                true,          // includeActivityMeta
                true,          // includeTeamNames
                true           // freeBlocks
            );

        // Nur Aktivitäten mit echtem Raum
        $activities = collect($activities)->filter(fn($a) => !empty($a->room_name) || !empty($a->room_id));

        // Gruppieren nach Raum
        $grouped = $activities->groupBy(function ($a) {
            $key = $a->room_id ?? $a->room_name;
            return (string) $key;
        });

        // Event laden
        $event = DB::table('event')
            ->join('plan', 'plan.event', '=', 'event.id')
            ->where('plan.id', $planId)
            ->select('event.*')
            ->first();
        $isMultidayEvent = (int)($event->days ?? 1) > 1;

        // Räume nach room.sequence sortieren (Fallback: Name)
        $roomIds = $activities
            ->pluck('room_id')
            ->filter()
            ->unique()
            ->values();

        $orderedRooms = DB::table('room')
            ->whereIn('id', $roomIds)
            ->select('id', 'name', 'sequence')
            ->orderByRaw('COALESCE(sequence, 9999)')
            ->orderBy('name')
            ->get();

        $roomEntries = collect();

        foreach ($orderedRooms as $room) {
            $roomEntries->push([
                'key'   => (string) $room->id,
                'label' => $room->name,
            ]);
        }

        $roomEntryKeys = $roomEntries->pluck('key')->all();
        $roomEntryLabels = $roomEntries
            ->pluck('label')
            ->map(fn($label) => mb_strtolower($label))
            ->all();

        $remainingEntries = $grouped->keys()
            ->map(function ($key) use ($grouped) {
                $first = optional($grouped->get($key))->first();
                $label = $first->room_name ?? $key;

                return [
                    'key'   => $key,
                    'label' => $label,
                ];
            })
            ->filter(function ($entry) use ($roomEntryKeys, &$roomEntryLabels) {
                if (in_array($entry['key'], $roomEntryKeys, true)) {
                    return false;
                }

                $labelKey = mb_strtolower($entry['label']);
                if (in_array($labelKey, $roomEntryLabels, true)) {
                    return false;
                }

                $roomEntryLabels[] = $labelKey;
                return true;
            })
            ->sortBy('label')
            ->values();

        $roomEntries = $roomEntries->concat($remainingEntries)->values();

        $html = '';

        $lastIndex = $roomEntries->count() - 1;

        foreach ($roomEntries as $idx => $roomEntry) {
            $roomKey = $roomEntry['key'];
            $roomLabel = $roomEntry['label'];

            $acts = $grouped->get($roomKey);
            if (!$acts) {
                continue;
            }

            $acts = $acts->sortBy([
                ['start_time', 'asc'],
                ['end_time', 'asc'],
            ]);

        $rows = $acts->map(function ($a) {
            $teamParts = [];

            // Hilfsfunktion für einheitliche Darstellung
            $formatTeam = function ($name, $numHot, $numInternal) {
                if (!empty($name) && !empty($numHot)) {
                    return "{$name} ({$numHot})";
                } elseif (!empty($name)) {
                    return $name;
                } elseif (!empty($numInternal)) {
                    return sprintf("T%02d !Platzhalter, weil nicht genügend Teams angemeldet sind!", $numInternal);
                } else {
                    return '–';
                }
            };

            // Jury (Lane)
            if (!empty($a->lane) && $a->team !== null) {
                $teamParts[] = $formatTeam(
                    $a->jury_team_name ?? null,
                    $a->jury_team_number_hot ?? null,
                    $a->team
                );
            }

            // Tisch 1
            if (!empty($a->table_1) && $a->table_1_team !== null) {
                $teamParts[] = $formatTeam(
                    $a->table_1_team_name ?? null,
                    $a->table_1_team_number_hot ?? null,
                    $a->table_1_team
                );
            }

            // Tisch 2
            if (!empty($a->table_2) && $a->table_2_team !== null) {
                $teamParts[] = $formatTeam(
                    $a->table_2_team_name ?? null,
                    $a->table_2_team_number_hot ?? null,
                    $a->table_2_team
                );
            }

            $teamDisplay = count($teamParts) ? implode(' / ', $teamParts) : '–';

            return [
                'start'    => \Carbon\Carbon::parse($a->start_time)->format('H:i'),
                'end'      => \Carbon\Carbon::parse($a->end_time)->format('H:i'),
                'is_free'  => $this->isFreeBlock($a),
                'activity' => $this->formatActivityLabel($a),
                'team'     => $teamDisplay,
                // 🔸 Icons vorbereiten (Logik bleibt hier, Blade rendert nur)
                'is_explore'    => in_array($a->activity_first_program_id, [FirstProgram::JOINT->value, FirstProgram::EXPLORE->value]),
                'is_challenge'  => in_array($a->activity_first_program_id, [FirstProgram::JOINT->value, FirstProgram::CHALLENGE->value]),
                // Add date information for day grouping
                'start_date' => \Carbon\Carbon::parse($a->start_time),
            ];
        })->values()->all();

            // Tabelle in mehrere Seiten splitten
            $chunks = array_chunk($rows, $maxRowsPerPage);
            $chunkCount = count($chunks);

            foreach ($chunks as $chunkIndex => $chunkRows) {
                $html .= view('pdf.content.room_schedule', [
                    'room'  => $roomLabel,
                    'rows'  => $chunkRows,
                    'event' => $event,
                    'multi_day_event' => $isMultidayEvent,
                    'page_date' => !empty($chunkRows) ? ($chunkRows[0]['start_date'] ?? null) : null,
                ])->render();

                // Seitenumbruch nach jedem Chunk (außer letzter)
                $isLastChunk = ($idx === $lastIndex) && ($chunkIndex === $chunkCount - 1);
                if (!$isLastChunk) {
                    $html .= '<div style="page-break-before: always;"></div>';
                }
            }
        }

// --- 🔹 Vorbereitung: Räume aus team_plan laden ---
$prepRooms = DB::table('team_plan')
    ->where('plan', $planId)
    ->whereNotNull('room')
    ->distinct()
    ->pluck('room');

if ($prepRooms->isNotEmpty()) {
    // Add page break before starting the preparation section
    $html .= '<div style="page-break-before: always;"></div>';
    
    // Raumdetails aus room-Tabelle
    $rooms = DB::table('room')
        ->whereIn('id', $prepRooms)
        ->select('id', 'name', 'sequence')
        ->orderByRaw('COALESCE(sequence, 9999)')
        ->orderBy('name')
        ->get();

    $roomCount = $rooms->count();
    $roomIndex = 0;

    foreach ($rooms as $room) {
        // Teams, die diesem Raum zugeordnet sind
        $teams = DB::table('team_plan')
            ->join('team', 'team_plan.team', '=', 'team.id')
            ->where('team_plan.plan', $planId)
            ->where('team_plan.room', $room->id)
            ->select(
                'team.name as team_name',
                'team.team_number_hot as team_number_hot',
                'team.first_program as program'
            )
            ->orderBy('team.team_number_hot')
            ->get();

        if ($teams->isEmpty()) {
            continue;
        }

        $roomIndex++;

        // Zeilen für Tabelle aufbauen
        $rows = $teams
            ->sortBy([
                ['program', 'asc'],
                ['team_name', 'asc'],
            ])
            ->values()
            ->map(function ($t) {
            return [
                'is_explore'   => in_array($t->program, [0, 2]),
                'is_challenge' => in_array($t->program, [0, 3]),
                'team_display' => trim($t->team_name . ' (' . $t->team_number_hot . ')'),
            ];
        });

        // Seite rendern
        $html .= view('pdf.content.room_schedule_preparation', [
            'room'  => $room->name,
            'rows'  => $rows,
            'event' => $event,
        ])->render();

        // Seitenumbruch nach jeder Raumseite (außer der letzten)
        if ($roomIndex < $roomCount) {
            $html .= '<div style="page-break-before: always;"></div>';
        }
    }
}

        // Jetzt EIN Layout drumherum bauen
        $layout = app(\App\Services\PdfLayoutService::class);
        $finalHtml = $layout->renderLayout($event, $html, 'FLOW Raumbeschilderung');

        // PDF im Querformat erzeugen
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($finalHtml, 'UTF-8')->setPaper('a4', 'landscape');

        return $pdf;
    }

    public function teamSchedulePdf(int $planId, array $programIds = [], $maxRowsPerPage = 16)
    {
        $fetcher = app(\App\Services\ActivityFetcherService::class);

        // If no program IDs provided, use both (backward compatibility)
        if (empty($programIds)) {
            $programIds = [2, 3]; // Explore, Challenge
        }

        $explorePages = [];
        $challengePages = [];

        // 1) Explore (Role 8) - only if program 2 selected
        if (in_array(2, $programIds)) {
            $exploreActs = collect($fetcher->fetchActivities(
                $planId,
                [8],   // Explore-Teams
                true,  // includeRooms
                true,  // includeGroupMeta
                true,  // includeActivityMeta (liefert activity_atd_name, activity_first_program_name, ...)
                true,  // includeTeamNames (jury_team_name, table_*_team_name)
                true   // freeBlocks
            ));
            $explorePages = $this->buildExploreTeamPages($exploreActs);
        }

        // 2) Challenge (Role 3) - only if program 3 selected
        if (in_array(3, $programIds)) {
            $challengeActs = collect($fetcher->fetchActivities(
                $planId,
                [3], true, true, true, true, true
            ));
            $challengePages = $this->buildChallengeTeamPages($challengeActs);
        }

        // Event laden (für Layout + QR/Link)
        $event = DB::table('event')
            ->join('plan', 'plan.event', '=', 'event.id')
            ->where('plan.id', $planId)
            ->select('event.*')
            ->first();
        // Multiday flag for per-page date bar and row limit adjustments
        $isMultidayEvent = (int)($event->days ?? 1) > 1;
        // If invoked from a caller that didn't reduce the page size already, do it here
        if ($isMultidayEvent && (int)$maxRowsPerPage > 14) {
            $maxRowsPerPage = 14;
        }

        // Explore zuerst, dann Challenge
        $pages = array_merge($explorePages, $challengePages);

        // 🔸 IDs für Match- und Check-Aktivitäten aus der DB holen (für Tischzuordnung)
        $matchCheckIds = DB::table('m_activity_type_detail')
            ->whereIn('code', ['r_match', 'r_check'])
            ->pluck('id')
            ->toArray();
        
        // 🔸 IDs für Check-Aktivitäten (für "Check für " Präfix)
        $checkIds = DB::table('m_activity_type_detail')
            ->where('code', 'r_check')
            ->pluck('id')
            ->toArray();

        // HTML bauen
        $html = '';
        $lastIndex = count($pages) - 1;

        foreach ($pages as $idx => $page) {
            // innerhalb des Teams chronologisch sortieren
            $acts = $page['acts']->sortBy([
                ['start_time', 'asc'],
                ['end_time', 'asc'],
            ]);

            // 🔹 Raumname aus team_plan → room
            $teamRoomName = '!Platzhalter, weil das Team noch keinem Raum zugeordnet wurde!';
            $roomData = null;

            $teamId = $page['team_id'] ?? null; // muss von deinen build*Pages mitgegeben werden
            if ($teamId) {
                $roomId = DB::table('team_plan')
                    ->where('plan', $planId)
                    ->where('team', $teamId)
                    ->value('room');

                if ($roomId) {
                    $roomData = DB::table('room')
                        ->where('id', $roomId)
                        ->select('name', 'navigation_instruction', 'is_accessible')
                        ->first();

                    if ($roomData && $roomData->name) {
                        $teamRoomName = $roomData->name;
                    }
                }
            }
            
            // Get team number for table assignment lookup
            $teamNumber = $page['team_number'] ?? null;

            // reguläre Aktivitäten sammeln
            $rows = $acts->map(function ($a) use ($matchCheckIds, $checkIds, $teamNumber) {
                $roomDisplay = $a->room_name ?? '–';
                
                // 🔸 For Challenge match/check activities, append table assignment
                if ($teamNumber && in_array($a->activity_type_detail_id, $matchCheckIds)) {
                    $tableName = null;
                    
                    // Check which table this team is assigned to
                    if (!is_null($a->table_1_team) && (int)$a->table_1_team === $teamNumber) {
                        $tableName = $a->table_1_name;
                    } elseif (!is_null($a->table_2_team) && (int)$a->table_2_team === $teamNumber) {
                        $tableName = $a->table_2_name;
                    }
                    
                    // Append table to room if found
                    if ($tableName) {
                        // For checks, add "Check für " prefix
                        $isCheck = in_array($a->activity_type_detail_id, $checkIds);
                        $tableDisplay = $isCheck ? 'Check für ' . $tableName : $tableName;
                        
                        if ($roomDisplay !== '–') {
                            $roomDisplay .= ' – ' . $tableDisplay;
                        } else {
                            $roomDisplay = $tableDisplay;
                        }
                    }
                }
                
                return [
                    'start'    => \Carbon\Carbon::parse($a->start_time)->format('H:i'),
                    'end'      => \Carbon\Carbon::parse($a->end_time)->format('H:i'),
                    'activity' => $this->formatActivityLabel($a),
                    'is_free'  => $this->isFreeBlock($a),
                    'room'     => $roomDisplay,
                    'start_date' => \Carbon\Carbon::parse($a->start_time), // Added for day grouping
                ];
            })->values()->all();
            
            // Collect unique rooms with navigation for legend
            $roomsWithNav = [];
            $rememberRoomHint = function ($name, $navigation, $isAccessible) use (&$roomsWithNav) {
                $label = is_string($name) ? trim($name) : '';
                if ($label === '') {
                    return;
                }

                $navigationText = is_string($navigation) ? $navigation : '';
                $hasNavigation = trim($navigationText) !== '';
                $accessible = $isAccessible === null ? true : (bool)$isAccessible;

                if (!$hasNavigation && $accessible) {
                    return;
                }

                $roomsWithNav[$label] = [
                    'navigation'    => $hasNavigation ? $navigationText : '',
                    'is_accessible' => $accessible,
                ];
            };
            
            // Add team's assigned room if it has information
            if ($roomData) {
                $rememberRoomHint(
                    $roomData->name ?? null,
                    $roomData->navigation_instruction ?? '',
                    $roomData->is_accessible ?? true
                );
            }
            
            // Add rooms from activities
            foreach ($acts as $a) {
                $rememberRoomHint(
                    $a->room_name ?? null,
                    $a->room_navigation ?? '',
                    $a->room_is_accessible ?? true
                );
            }

            // ➕ Zusatzzeile "Teambereich"
            // Use first activity's date if available, otherwise use a default
            $firstDate = !empty($rows) && isset($rows[0]['start_date']) 
                ? $rows[0]['start_date'] 
                : \Carbon\Carbon::now();
            
            array_unshift($rows, [
                'start'    => '',
                'end'      => '',
                'activity' => 'Teambereich',
                'room'     => $teamRoomName,
                'start_date' => $firstDate,
            ]);
            
            // Chunk rows uniformly (independent of day changes)
            $chunks = array_chunk($rows, $maxRowsPerPage);
            $chunkCount = count($chunks);

            foreach ($chunks as $chunkIndex => $chunkRows) {
                $html .= view('pdf.content.team_schedule', [
                    'team'  => $page['label'], // z.B. "Explore 12 – RoboKids"
                    'rows'  => $chunkRows,
                    'event' => $event,
                    'multi_day_event' => isset($isMultidayEvent) ? $isMultidayEvent : false,
                    'page_date' => !empty($chunkRows) ? ($chunkRows[0]['start_date'] ?? null) : null,
                    // Provide roomsWithNav on every chunk to keep right column complete
                    'roomsWithNav' => $roomsWithNav,
                ])->render();

                // Seitenumbruch nach jedem Chunk, außer dem letzten der letzten Seite
                $isLastChunk = ($idx === $lastIndex) && ($chunkIndex === $chunkCount - 1);
                if (!$isLastChunk) {
                    $html .= '<div style="page-break-before: always;"></div>';
                }
            }
            
            // Do not add an extra page break here; the chunk loop already adds breaks between pages/teams.
        }

        $layout = app(\App\Services\PdfLayoutService::class);
        $finalHtml = $layout->renderLayout($event, $html, 'FLOW Teambeschilderung');

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($finalHtml, 'UTF-8')
            ->setPaper('a4', 'landscape');

        return $pdf;
    }

    /**
     * EXPLORE: Teamnummer nur in `team` (Jury). 
     * Globale Acts = `team === null` → jedem Explore-Team hinzufügen.
     * Ergebnis: Array von Seiten ['label' => string, 'acts' => Collection], nach Teamnummer sortiert.
     */
    private function buildExploreTeamPages(\Illuminate\Support\Collection $acts): array
    {
        // Teamnummern + Namen + IDs sammeln
        $teamNames = []; // [num => name]
        $teamHot   = []; // [num => team_number_hot]
        $teamIds   = []; // [num => actual team.id]
        $teamSet   = []; // num als key

        foreach ($acts as $a) {
            if (!is_null($a->team)) { // Jury-Teamnummer
                $num = (int)$a->team;
                $teamSet[$num] = true;

                if (!empty($a->jury_team_name) && empty($teamNames[$num])) {
                    $teamNames[$num] = $a->jury_team_name;
                }

                if (isset($a->jury_team_number_hot)) {
                    $teamHot[$num] = $a->jury_team_number_hot;
                }
                
                // Store actual team ID
                if (isset($a->jury_team_id)) {
                    $teamIds[$num] = $a->jury_team_id;
                }
            }
        }

        if (empty($teamSet)) {
            return [];
        }

        // Globale Acts (ohne Teamnummer)
        $globalActs = $acts->filter(fn($a) => is_null($a->team));

        // Pro Team: eigene + globale Acts
        $pages = [];
        $teamNums = array_keys($teamSet);
        sort($teamNums, SORT_NUMERIC);

        foreach ($teamNums as $num) {
            $ownActs = $acts->filter(fn($a) => !is_null($a->team) && (int)$a->team === $num);

            // 🔹 Label nach neuer Regel
            $teamName = $teamNames[$num] ?? null;
            $teamHotNum = $teamHot[$num] ?? null;

            if ($teamName && $teamHotNum) {
                $label = "FLL Explore {$teamName} ({$teamHotNum})";
            } elseif ($teamName) {
                $label = "FLL Explore {$teamName}";
            } elseif ($num > 0) {
                $label = sprintf("FLL Explore T%02d !Platzhalter, weil nicht genügend Teams angemeldet sind!", $num);
            } else {
                $label = "FLL Explore –";
            }

            $pages[] = [
                'label' => $label,
                'team_id' => $teamIds[$num] ?? null, // Use actual team.id, not plan number
                'team_number' => $num, // Plan team number for table assignment lookup
                'acts'  => $ownActs->concat($globalActs),
            ];
        }

        return $pages;
    }

    /**
     * CHALLENGE: Teamnummer kann in `team` (Jury) ODER `table_1_team` ODER `table_2_team` stehen.
     * Globale Acts = alle drei NULL → jedem Challenge-Team hinzufügen.
     * Ergebnis: Array von Seiten ['label' => string, 'acts' => Collection], nach Teamnummer sortiert.
     */
    private function buildChallengeTeamPages(\Illuminate\Support\Collection $acts): array
    {
        $teamNames = []; // [num => name]
        $teamHot   = []; // [num => team_number_hot]
        $teamIds   = []; // [num => actual team.id]
        $teamSet   = [];

        foreach ($acts as $a) {
            // Jury
            if (!is_null($a->team)) {
                $n = (int)$a->team;
                if ($n <= 0) {
                    continue;
                }
                $teamSet[$n] = true;
                if (!empty($a->jury_team_name) && empty($teamNames[$n])) {
                    $teamNames[$n] = $a->jury_team_name;
                }
                if (isset($a->jury_team_number_hot)) {
                    $teamHot[$n] = $a->jury_team_number_hot;
                }
                if (isset($a->jury_team_id) && !isset($teamIds[$n])) {
                    $teamIds[$n] = $a->jury_team_id;
                }
            }

            // Table 1
            if (!is_null($a->table_1_team)) {
                $n = (int)$a->table_1_team;
                if ($n <= 0) {
                    continue;
                }
                $teamSet[$n] = true;
                if (!empty($a->table_1_team_name) && empty($teamNames[$n])) {
                    $teamNames[$n] = $a->table_1_team_name;
                }
                if (isset($a->table_1_team_number_hot)) {
                    $teamHot[$n] = $a->table_1_team_number_hot;
                }
                if (isset($a->table_1_team_id) && !isset($teamIds[$n])) {
                    $teamIds[$n] = $a->table_1_team_id;
                }
            }

            // Table 2
            if (!is_null($a->table_2_team)) {
                $n = (int)$a->table_2_team;
                if ($n <= 0) {
                    continue;
                }
                $teamSet[$n] = true;
                if (!empty($a->table_2_team_name) && empty($teamNames[$n])) {
                    $teamNames[$n] = $a->table_2_team_name;
                }
                if (isset($a->table_2_team_number_hot)) {
                    $teamHot[$n] = $a->table_2_team_number_hot;
                }
                if (isset($a->table_2_team_id) && !isset($teamIds[$n])) {
                    $teamIds[$n] = $a->table_2_team_id;
                }
            }
        }

        if (empty($teamSet)) {
            return [];
        }

        // 🔸 IDs für Match- und Check-Aktivitäten aus der DB holen
        $matchCheckIds = DB::table('m_activity_type_detail')
            ->whereIn('code', ['r_match', 'r_check'])
            ->pluck('id')
            ->toArray();

        // 🔸 Globale Acts: kein Team, UND kein Match / kein Check
        $globalActs = $acts->filter(function ($a) use ($matchCheckIds) {
            $hasNoTeam = is_null($a->team) && is_null($a->table_1_team) && is_null($a->table_2_team);

            // Wenn kein Team → prüfen, ob Activity-Typ einer der Match-/Check-Typen ist
            $isMatchOrCheck = in_array($a->activity_type_detail_id, $matchCheckIds);

            return $hasNoTeam && !$isMatchOrCheck;
        });

        $pages    = [];
        $teamNums = array_keys($teamSet);
        sort($teamNums, SORT_NUMERIC);

        foreach ($teamNums as $num) {
            if ($num <= 0) {
                continue;
            }
            // Alle Acts, die dieses Team betreffen (Jury ODER Table1 ODER Table2)
            $ownActs = $acts->filter(function ($a) use ($num) {
                return (!is_null($a->team) && (int)$a->team === $num)
                    || (!is_null($a->table_1_team) && (int)$a->table_1_team === $num)
                    || (!is_null($a->table_2_team) && (int)$a->table_2_team === $num);
            });

            // 🔹 Label-Logik wie bei Explore
            $teamName = $teamNames[$num] ?? null;
            $teamHotNum = $teamHot[$num] ?? null;

            if ($teamName && $teamHotNum) {
                $label = "FLL Challenge {$teamName} ({$teamHotNum})";
            } elseif ($teamName) {
                $label = "FLL Challenge {$teamName}";
            } elseif ($num > 0) {
                $label = sprintf("FLL Challenge T%02d !Platzhalter, weil nicht genügend Teams angemeldet sind!", $num);
            } else {
                $label = "FLL Challenge –";
            }

            $pages[] = [
                'label' => $label,
                'team_id' => $teamIds[$num] ?? null, // Use actual team.id, not plan number
                'team_number' => $num, // Plan team number for table assignment lookup
                'acts'  => $ownActs->concat($globalActs),
            ];
        }

        return $pages;
    }




    public function roleSchedulePdf(int $planId, array $roleIds = [], $maxRowsPerPage = 16)
    {
        $fetcher = app(\App\Services\ActivityFetcherService::class);

        // If no role IDs provided, use default set (backward compatibility)
        if (empty($roleIds)) {
            $roleIds = [9, 4, 5, 11]; // EXPLORE Jury, CHALLENGE Jury, Referees, Robot Check
        }

        // Fetch activities for all selected roles and tag them with their role_id
        $allActivities = collect();
        foreach ($roleIds as $roleId) {
            $acts = $fetcher->fetchActivities($planId, [$roleId], true, true, true, true, true);
            if ($acts->isNotEmpty()) {
                // Add role_id to each activity for filtering
                $acts = $acts->map(function($activity) use ($roleId) {
                    $activity->role_id = $roleId;
                    return $activity;
                });
                $allActivities = $allActivities->merge($acts);
            }
        }

        // Group activities by role for backward compatibility with existing logic
        // The rest of the method expects specific variables like $exploreActs, etc.
        $exploreActs = $allActivities->filter(fn($a) => $a->role_id == 9);
        $challengeJuryActs = $allActivities->filter(fn($a) => $a->role_id == 4);
        $challengeRefActs = $allActivities->filter(fn($a) => $a->role_id == 5);
        $challengeCheckActs = $allActivities->filter(fn($a) => $a->role_id == 11);
        $liveChallengeActs = $allActivities->filter(fn($a) => $a->role_id == 16);

        $roleMeta = DB::table('m_role')
            ->whereIn('id', $allActivities->pluck('role_id')->filter()->unique()->values())
            ->select('id', 'first_program', 'sequence')
            ->get()
            ->keyBy('id');

        // === Event laden ===
        $event = DB::table('event')
            ->join('plan', 'plan.event', '=', 'event.id')
            ->where('plan.id', $planId)
            ->select('event.*')
            ->first();
        $isMultidayEvent = (int)($event->days ?? 1) > 1;

        /**
         * Hilfsfunktion: verteilt "allgemeine" Aktivitäten auf alle Gruppen
         */
        $distributeGeneric = function ($activities, string $groupKey, string $labelPrefix) {
            $collection = collect($activities);
            $finalKey = $groupKey;

            // Robot Game: table -> ref_table (+ Name)
            if ($groupKey === 'table') {
                $expanded = collect();
                foreach ($collection as $a) {
                    $made = 0;

                    if (!empty($a->table_1)) {
                        $c = clone $a;
                        $c->ref_table = (int)$a->table_1;
                        $c->ref_table_name = $a->table_1_name ?? null;
                        $expanded->push($c);
                        $made++;
                    }
                    if (!empty($a->table_2)) {
                        $c = clone $a;
                        $c->ref_table = (int)$a->table_2;
                        $c->ref_table_name = $a->table_2_name ?? null;
                        $expanded->push($c);
                        $made++;
                    }
                    if ($made === 0) {
                        // generisch, ohne Tisch
                        $expanded->push($a);
                    }
                }
                $collection = $expanded;
                $finalKey = 'ref_table';
            }

            // mit/ohne Schlüssel trennen
            $withKey    = $collection->filter(fn($a) => !empty($a->{$finalKey}));
            $withoutKey = $collection->filter(fn($a) => empty($a->{$finalKey}));

            // alle vorhandenen Keys (z. B. 1,2,3,4)
            $allKeys = $withKey->pluck($finalKey)->filter()->unique()->values();

            // Namens-Mapping für ref_table (übersteuerte Namen beibehalten)
            $nameMap = [];
            if ($finalKey === 'ref_table') {
                $nameMap = $withKey->mapWithKeys(function ($a) use ($finalKey) {
                    $num = (int)$a->{$finalKey};
                    $name = $a->ref_table_name ?? "Tisch {$num}";
                    return [$num => $name];
                })->toArray();
            }

            // generische Aktivitäten auf alle Keys duplizieren + Namen vererben
            foreach ($withoutKey as $generic) {
                foreach ($allKeys as $keyValue) {
                    $clone = clone $generic;
                    $clone->{$finalKey} = (int)$keyValue;
                    if ($finalKey === 'ref_table') {
                        $clone->ref_table_name = $nameMap[(int)$keyValue] ?? "Tisch " . (int)$keyValue;
                    }
                    $withKey->push($clone);
                }
            }

            // Gruppieren + Label
            return $withKey->groupBy(function ($a) use ($finalKey, $labelPrefix) {
                if ($finalKey === 'lane') {
                    return "{$labelPrefix} {$a->lane}";
                }
                if ($finalKey === 'ref_table') {
                    $num  = $a->ref_table ?? null;
                    $name = $a->ref_table_name ?? ($num ? "Tisch {$num}" : 'Tisch');
                    return "{$labelPrefix}{$name}";
                }
                $val = $a->{$finalKey};
                return "{$labelPrefix} – {$val}";
            });
        };

        // === Gruppieren & Duplizieren ===
        $exploreGrouped       = $distributeGeneric($exploreActs, 'lane', 'FLL Explore Gutachter:innen-Gruppe');
        $challengeJuryGrouped = $distributeGeneric($challengeJuryActs, 'lane', 'FLL Challenge Jury-Gruppe');
        $challengeRefGrouped  = $distributeGeneric($challengeRefActs, 'table', 'FLL Challenge Schiedsrichter:innen ');
        $challengeCheckGrouped= $distributeGeneric($challengeCheckActs, 'table', 'FLL Challenge Robot-Check für ');
        $liveChallengeGrouped = $distributeGeneric($liveChallengeActs, 'lane', 'Live-Challenge Jury-Gruppe');

        // === Zusammenführen mit Rollen-Metadaten ===
        $sections = collect();
        $appendSections = function ($grouped, $roleId) use (&$sections, $roleMeta) {
            if ($grouped->isEmpty()) {
                return;
            }

            $meta = $roleMeta->get($roleId);
            $programOrder  = $meta->first_program ?? 999;
            $sequenceOrder = $meta->sequence ?? 9999;

            foreach ($grouped->sortKeys() as $label => $collection) {
                $sections->push([
                    'title'          => $label,
                    'activities'     => $collection,
                    'program_order'  => $programOrder,
                    'sequence_order' => $sequenceOrder,
                ]);
            }
        };

        $appendSections($exploreGrouped, 9);
        $appendSections($challengeJuryGrouped, 4);
        $appendSections($challengeRefGrouped, 5);
        $appendSections($challengeCheckGrouped, 11);
        $appendSections($liveChallengeGrouped, 16);

        $sections = $sections->sortBy([
            ['program_order', 'asc'],
            ['sequence_order', 'asc'],
            ['title', 'asc'],
        ])->values();

        // === Rendern aller Abschnitte ===
        $html = '';
        $last = $sections->count() - 1;

        foreach ($sections as $i => $section) {
            $acts = $section['activities']->sortBy([
                ['start_time', 'asc'],
                ['end_time', 'asc'],
            ]);

            // Collect unique rooms with navigation for legend
            $roomsWithNav = [];
            $rememberRoomHint = function ($name, $navigation, $isAccessible) use (&$roomsWithNav) {
                $label = is_string($name) ? trim($name) : '';
                if ($label === '') {
                    return;
                }

                $navigationText = is_string($navigation) ? $navigation : '';
                $hasNavigation = trim($navigationText) !== '';
                $accessible = $isAccessible === null ? true : (bool)$isAccessible;

                if (!$hasNavigation && $accessible) {
                    return;
                }

                $roomsWithNav[$label] = [
                    'navigation'    => $hasNavigation ? $navigationText : '',
                    'is_accessible' => $accessible,
                ];
            };
            foreach ($acts as $a) {
                $rememberRoomHint(
                    $a->room_name ?? null,
                    $a->room_navigation ?? '',
                    $a->room_is_accessible ?? true
                );
            }
            
            $rows = $acts->map(fn($a) => [
                'start'    => \Carbon\Carbon::parse($a->start_time)->format('H:i'),
                'end'      => \Carbon\Carbon::parse($a->end_time)->format('H:i'),
                'activity' => $this->formatActivityLabel($a),
                'is_free'  => $this->isFreeBlock($a),
                'team' => (function () use ($a) {
                    // Helper für Formatierung
                    $fmtNameHot = function (?string $name, $hot) {
                        if ($name && $name !== '') {
                            return $hot !== null ? "{$name} ({$hot})" : $name;
                        }
                        return null;
                    };
                    $fmtInternal = function ($num) {
                        if ($num !== null && $num !== '' && (int)$num > 0) {
                            return sprintf('T%02d !Platzhalter, weil nicht genügend Teams angemeldet sind!', (int)$num);
                        }
                        return null;
                    };

                    // 1) Jury
                    $val = $fmtNameHot($a->jury_team_name ?? null, $a->jury_team_number_hot ?? null)
                        ?? $fmtInternal(($a->jury_team ?? null) ?? ($a->team ?? null)); // $a->team ist Alias auf jury_team
                    if ($val) return $val;

                    // 2) Tisch 1
                    $val = $fmtNameHot($a->table_1_team_name ?? null, $a->table_1_team_number_hot ?? null)
                        ?? $fmtInternal($a->table_1_team ?? null);
                    if ($val) return $val;

                    // 3) Tisch 2
                    $val = $fmtNameHot($a->table_2_team_name ?? null, $a->table_2_team_number_hot ?? null)
                        ?? $fmtInternal($a->table_2_team ?? null);
                    if ($val) return $val;

                    // 4) Generisch
                    return '–';
                })(),
                'room'     => $a->room_name ?? '–',
                // Add date information for day grouping
                'start_date' => \Carbon\Carbon::parse($a->start_time),
            ])->values()->all();

            // Teile das Array in Seitenblöcke
            $chunks = array_chunk($rows, $maxRowsPerPage);
            $chunkCount = count($chunks);

            foreach ($chunks as $chunkIndex => $chunkRows) {
                $html .= view('pdf.content.role_schedule', [
                    'title' => $section['title'],
                    'rows'  => $chunkRows,
                    'event' => $event,
                    'multi_day_event' => isset($isMultidayEvent) ? $isMultidayEvent : false,
                    'page_date' => !empty($chunkRows) ? ($chunkRows[0]['start_date'] ?? null) : null,
                    'roomsWithNav' => $chunkIndex === 0 ? $roomsWithNav : [], // Only on first chunk
                ])->render();

                // Seitenumbruch nach jedem Chunk außer dem letzten
                $isLastChunk = ($i === $last) && ($chunkIndex === $chunkCount - 1);
                if (!$isLastChunk) {
                    $html .= '<div style="page-break-before: always;"></div>';
                }
            }
        }

        // === Gesamtes Layout + PDF ===
        $layout = app(\App\Services\PdfLayoutService::class);
        $finalHtml = $layout->renderLayout($event, $html, 'FLOW Jury & Robot Game');

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($finalHtml, 'UTF-8')->setPaper('a4', 'landscape');
        return $pdf;
    }


    /**
     * Prüft, ob alle relevanten Daten konsistent und vollständig sind.
     *
     * @param int $planId
     * @return \Illuminate\Http\JsonResponse
     */
    public function dataReadiness(int $eventId)
    {
        $plan = DB::table('plan')->where('event', $eventId)->first();
        if (!$plan) {
            return response()->json([
                'explore_teams_ok'   => false,
                'challenge_teams_ok' => false,
                'room_mapping_ok'    => false,
            ]);
        }

        // Geplante vs. angemeldete Teams prüfen ---

        $paramIds = DB::table('m_parameter')
            ->whereIn('name', ['c_teams', 'e_teams'])
            ->pluck('id', 'name');

        $values = DB::table('plan_param_value')
            ->where('plan', $plan->id)
            ->whereIn('parameter', $paramIds->values())
            ->pluck('set_value', 'parameter')
            ->map(fn($v) => (int)$v);

        $plannedChallengeTeams = $values[$paramIds['c_teams']] ?? 0;
        $plannedExploreTeams   = $values[$paramIds['e_teams']] ?? 0;

        $drahtController = app(DrahtController::class);
        $response = $drahtController->show(Event::findOrFail($eventId));
        $drahtData = $response->getData(true);

        $registeredChallengeTeams = isset($drahtData['teams_challenge'])
            ? count($drahtData['teams_challenge'])
            : 0;

        $registeredExploreTeams = isset($drahtData['teams_explore'])
            ? count($drahtData['teams_explore'])
            : 0;


        // Raum-Mapping prüfen ---    
        $planRoomTypeController = app(PlanRoomTypeController::class);
        $unmappedResponse = $planRoomTypeController->unmappedRoomTypes($plan->id);
        $unmappedList = $unmappedResponse->getData(true);

        // Wenn kein RoomType ohne Mapping gefunden → alles gut
        $hasUnmappedRooms = !empty($unmappedList);

        // Check if all teams have a room assigned

        // --- Team-Mapping prüfen ---
        $teamController = app(TeamController::class);

        $event = Event::find($eventId);

        // Explore Teams
        $requestExplore = new \Illuminate\Http\Request();
        $requestExplore->query->set('program', 'explore');
        $exploreResponse = $teamController->index($requestExplore, $event);
        $exploreTeams = collect($exploreResponse->getData(true));

        // Log::debug('Explore Teams:', $exploreTeams->toArray());

        // Challenge Teams
        $requestChallenge = new \Illuminate\Http\Request();
        $requestChallenge->query->set('program', 'challenge');
        $challengeResponse = $teamController->index($requestChallenge, $event);
        $challengeTeams = collect($challengeResponse->getData(true));

        // Log::debug('Challenge Teams:', $challengeTeams->toArray());

        // Prüfen, ob alle Teams einen Raum haben
        $exploreWithoutRoom = $exploreTeams->whereNull('room')->count();
        $challengeWithoutRoom = $challengeTeams->whereNull('room')->count();

        $allExploreRoomsOk = $exploreTeams->isEmpty() || $exploreWithoutRoom === 0;
        $allChallengeRoomsOk = $challengeTeams->isEmpty() || $challengeWithoutRoom === 0;

        // --- Ergebnis zusammensetzen ---
        $result = [
            'explore_teams_ok'   => ($plannedExploreTeams === $registeredExploreTeams) ,
            'challenge_teams_ok' => ($plannedChallengeTeams === $registeredChallengeTeams) ,
            'room_mapping_ok'    => !$hasUnmappedRooms && $allExploreRoomsOk && $allChallengeRoomsOk,
            'room_mapping_details' => [
                'activities_ok' => !$hasUnmappedRooms,
                'teams_ok'     => $allExploreRoomsOk && $allChallengeRoomsOk,
            ],
        ];

        return response()->json($result);
    }

    /**
     * Get event overview data for both PDF and HTML rendering
     */
    public function getEventOverviewData(int $planId, array $roles = [6, 10, 14], bool $isPdf = true): array
    {
        // Get activities using specified roles
        $activities = $this->activityFetcher->fetchActivities(
            plan: $planId,
            roles: $roles,
            includeGroupMeta: true,
            freeBlocks: true
        );

        if ($activities->isEmpty()) {
            throw new \Exception('No activities found for this plan');
        }

        // Group activities by activity_group_id to ensure each group appears as separate block
        $groupedActivities = $activities->groupBy('activity_group_id');
        
        $eventOverview = [];

        foreach ($groupedActivities as $groupId => $groupActivities) {
            $startTimes = $groupActivities->pluck('start_time')->map(function($time) {
                return Carbon::parse($time);
            });
            $endTimes = $groupActivities->pluck('end_time')->map(function($time) {
                return Carbon::parse($time);
            });

            $earliestStart = $startTimes->min();
            $latestEnd = $endTimes->max();

            // Get group metadata from first activity
            $firstActivity = $groupActivities->first();
            
            $eventOverview[] = [
                'group_id' => $groupId,
                'group_name' => $firstActivity->group_atd_name ?? 'Unknown Group',
                'group_description' => $firstActivity->group_description ?? '',
                'group_first_program_id' => $firstActivity->group_first_program_id ?? null,
                'group_overview_plan_column' => $firstActivity->group_overview_plan_column ?? null,
                'earliest_start' => $earliestStart,
                'latest_end' => $latestEnd,
                'duration_minutes' => $earliestStart->diffInMinutes($latestEnd),
                'activity_count' => $groupActivities->count()
            ];
        }

        // Sort by earliest start time
        usort($eventOverview, function($a, $b) {
            return $a['earliest_start']->timestamp - $b['earliest_start']->timestamp;
        });

        // Manual assignment of free blocks to program-specific Allgemein columns
        foreach ($eventOverview as &$event) {
            if (($event['group_overview_plan_column'] === 'Allgemein' || $event['group_overview_plan_column'] === null) && $event['group_first_program_id'] !== null) {
                // This is a free block - assign to program-specific Allgemein column
                if ($event['group_first_program_id'] == 2) {
                    $event['group_overview_plan_column'] = 'Allgemein-2'; // Explore
                } elseif ($event['group_first_program_id'] == 3) {
                    $event['group_overview_plan_column'] = 'Allgemein-3'; // Challenge
                }
            }
        }
        unset($event); // Clear the reference

        // Pre-assign activities to their correct columns
        foreach ($eventOverview as &$event) {
            $event['assigned_column'] = $event['group_overview_plan_column'] ?? 'Allgemein';
            
            // Handle empty strings as well as null
            if (empty($event['assigned_column'])) {
                $event['assigned_column'] = 'Allgemein';
            }
            
            // For Allgemein columns, include first_program to make them unique
            if ($event['assigned_column'] === 'Allgemein') {
                $program = $event['group_first_program_id'];
                if ($program === null) {
                    $event['assigned_column'] = 'Allgemein';
                } else {
                    $event['assigned_column'] = 'Allgemein-' . $program;
                }
            }
        }
        unset($event); // Clear the reference

        // Get unique assigned columns for sorting
        $columnNames = collect($eventOverview)
            ->pluck('assigned_column')
            ->unique()
            ->sortBy(function($columnName) {
                // Custom sorting to ensure specific column order
                $customOrder = [
                    'Allgemein' => 0,
                    'Allgemein-2' => 1,
                    'Explore' => 2,
                    'Allgemein-3' => 3,
                    'Challenge' => 4,
                    'Robot-Game' => 5,
                    'Live-Challenge' => 6
                ];
                
                return $customOrder[$columnName] ?? 999;
            })
            ->values()
            ->toArray();

        // Group by day for display
        $eventsByDay = [];
        foreach ($eventOverview as $event) {
            $dayKey = $event['earliest_start']->format('Y-m-d');
            if (!isset($eventsByDay[$dayKey])) {
                $eventsByDay[$dayKey] = [
                    'date' => $event['earliest_start'],
                    'events' => []
                ];
            }
            $eventsByDay[$dayKey]['events'][] = $event;
        }

        if ($isPdf) {
            // PDF: Calculate global time range for all days (consistent rows)
            $globalEarliestHour = null;
            $globalLatestHour = null;

            // First pass: calculate global time range
            foreach($eventsByDay as $dayKey => $dayData) {
                $allEvents = collect($dayData['events']);
                $earliestStart = $allEvents->min('earliest_start');
                $latestEnd = $allEvents->max('latest_end');
                
                // Find earliest and latest hours for this day
                $dayEarliestHour = $earliestStart->hour;
                $dayLatestHour = $latestEnd->hour;
                // Round up to the next 10-minute slot instead of the next hour
                $latestMinutes = $latestEnd->minute;
                if ($latestMinutes > 0) {
                    // Round up to next 10-minute boundary
                    $roundedMinutes = ceil($latestMinutes / 10) * 10;
                    if ($roundedMinutes >= 60) {
                        $dayLatestHour++;
                        $roundedMinutes = 0;
                    }
                }
                
                // Update global min/max hours
                if ($globalEarliestHour === null || $dayEarliestHour < $globalEarliestHour) {
                    $globalEarliestHour = $dayEarliestHour;
                }
                if ($globalLatestHour === null || $dayLatestHour > $globalLatestHour) {
                    $globalLatestHour = $dayLatestHour;
                }
            }

            // Create 10-minute grid from global earliest hour to actual latest end time
            $startTime = \Carbon\Carbon::createFromTime($globalEarliestHour, 0, 0);
            
            // Find the actual latest end time across all days
            $actualLatestEnd = null;
            foreach($eventsByDay as $dayData) {
                $allEvents = collect($dayData['events']);
                $latestEnd = $allEvents->max('latest_end');
                if ($actualLatestEnd === null || $latestEnd->gt($actualLatestEnd)) {
                    $actualLatestEnd = $latestEnd;
                }
            }
            
            // Round up to x:50 to show complete last hour (6 rows)
            // Always stay in the same hour, never move to next hour
            $endMinutes = $actualLatestEnd->minute;
            $endHour = $actualLatestEnd->hour;
            $roundedMinutes = 50; // Always end at x:50 to show complete current hour
            $endTime = \Carbon\Carbon::createFromTime($endHour, $roundedMinutes, 0);

            // Generate all 10-minute slots
            $timeSlots = [];
            $current = $startTime->copy();
            while ($current->lte($endTime)) {
                $timeSlots[] = $current->copy();
                $current->addMinutes(10);
            }
            
            // Add timeSlots to each day for PDF
            foreach($eventsByDay as $dayKey => &$dayData) {
                $dayData['timeSlots'] = $timeSlots;
            }
        } else {
            // Preview: Calculate per-day time ranges (compact, space-saving)
            $globalEarliestHour = null;
            $globalLatestHour = null;

            foreach($eventsByDay as $dayKey => &$dayData) {
                $allEvents = collect($dayData['events']);
                $earliestStart = $allEvents->min('earliest_start');
                $latestEnd = $allEvents->max('latest_end');
                
                // Find earliest and latest hours for this day
                $dayEarliestHour = $earliestStart->hour;
                $dayLatestHour = $latestEnd->hour;
                // Round up to the next 10-minute slot instead of the next hour
                $latestMinutes = $latestEnd->minute;
                if ($latestMinutes > 0) {
                    // Round up to next 10-minute boundary
                    $roundedMinutes = ceil($latestMinutes / 10) * 10;
                    if ($roundedMinutes >= 60) {
                        $dayLatestHour++;
                        $roundedMinutes = 0;
                    }
                }
                
                // Update global min/max for return values
                if ($globalEarliestHour === null || $dayEarliestHour < $globalEarliestHour) {
                    $globalEarliestHour = $dayEarliestHour;
                }
                if ($globalLatestHour === null || $dayLatestHour > $globalLatestHour) {
                    $globalLatestHour = $dayLatestHour;
                }

                // Generate 10-minute slots for this day only
                $dayStartTime = \Carbon\Carbon::createFromTime($dayEarliestHour, 0, 0);
                
                // Round up to x:50 to show complete last hour (6 rows)
                // Always stay in the same hour, never move to next hour
                $latestMinutes = $latestEnd->minute;
                $latestHour = $latestEnd->hour;
                $roundedMinutes = 50; // Always end at x:50 to show complete current hour
                $dayEndTime = \Carbon\Carbon::createFromTime($latestHour, $roundedMinutes, 0);
                
                $dayTimeSlots = [];
                $current = $dayStartTime->copy();
                while ($current->lte($dayEndTime)) {
                    $dayTimeSlots[] = $current->copy();
                    $current->addMinutes(10);
                }
                
                // Add per-day timeSlots
                $dayData['timeSlots'] = $dayTimeSlots;
            }
            
            // For preview, we don't need a global timeSlots array
            $timeSlots = [];
        }

        // Check if this is a multi-day event
        $isMultiDay = count($eventsByDay) > 1;

        return [
            'eventsByDay' => $eventsByDay,
            'columnNames' => $columnNames,
            'isMultiDay' => $isMultiDay,
            'timeSlots' => $timeSlots,
            'globalEarliestHour' => $globalEarliestHour,
            'globalLatestHour' => $globalLatestHour,
            'startTime' => $isPdf ? $startTime : null,
            'endTime' => $isPdf ? $endTime : null
        ];
    }


    /**
     * Generate event overview PDF - chronological list of activity groups
     */
    public function eventOverviewPdf(int $planId)
    {
        try {
            // Get event overview data using shared method
            $data = $this->getEventOverviewData($planId);
            $eventsByDay = $data['eventsByDay'];
            $columnNames = $data['columnNames'];

            // Get event data for header
            $event = DB::table('event')
                ->join('plan', 'plan.event', '=', 'event.id')
                ->where('plan.id', $planId)
                ->select('event.*')
                ->first();

            if (!$event) {
                return response()->json(['error' => 'Event not found'], 404);
            }

            // Generate content HTML using the event-overview template
            $contentHtml = view('pdf.event-overview', [
                'eventsByDay' => $eventsByDay,
                'columnNames' => $columnNames,
                'planId' => $planId
            ])->render();

            // Use portrait layout specifically for overview PDF
            $header = $this->buildHeaderData($event);
            $footerLogos = $this->buildFooterLogos($event->id);
            
            $finalHtml = view('pdf.layout_portrait', [
                'title' => 'FLOW Übersichtsplan',
                'header' => $header,
                'footerLogos' => $footerLogos,
                'contentHtml' => $contentHtml,
            ])->render();

            // Generate PDF in portrait orientation
            $pdf = Pdf::loadHTML($finalHtml, 'UTF-8')->setPaper('a4', 'portrait');

            // Get plan info for filename
            $plan = DB::table('plan')
                ->where('id', $planId)
                ->select('last_change')
                ->first();

            // Format date for filename
            $formattedDate = $plan && $plan->last_change
                ? \Carbon\Carbon::parse($plan->last_change)
                    ->timezone('Europe/Berlin')
                    ->format('d.m.y')
                : now()->format('d.m.y');

            $filename = "FLOW_Übersichtsplan_({$formattedDate}).pdf";

            // Umlaute transliterieren
            $filename = str_replace(
                ['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'],
                ['ae', 'oe', 'ue', 'Ae', 'Oe', 'Ue', 'ss'],
                $filename
            );

            // Return PDF with proper headers for filename
            return response($pdf->output(), 200)
                ->header('Content-Type', 'application/pdf')
                ->header('X-Filename', $filename)
                ->header('Access-Control-Expose-Headers', 'X-Filename');

        } catch (\Exception $e) {
            Log::error('Event overview PDF generation failed', [
                'plan_id' => $planId,
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => 'PDF generation failed'], 500);
        }
    }

    /**
     * Build header data for PDF (copied from PdfLayoutService)
     */
    private function buildHeaderData(object $event): array
    {
        $formattedDate = '';
        if (!empty($event->date)) {
            try {
                $startDate = Carbon::parse($event->date);
                
                // Check if this is a multi-day event
                if (!empty($event->days) && $event->days > 1) {
                    // Multi-day event: show date range
                    $endDate = $startDate->copy()->addDays($event->days - 1);
                    $formattedDate = $startDate->format('d.m') . '-' . $endDate->format('d.m.Y');
                } else {
                    // Single-day event: show just the date
                    $formattedDate = $startDate->format('d.m.Y');
                }
            } catch (\Throwable $e) {
                $formattedDate = (string) $event->date;
            }
        }

        $leftLogos = [];
        if (!empty($event->event_explore)) {
            $leftLogos[] = $this->toDataUri(public_path('flow/fll_explore_hs.png'));
        }
        if (!empty($event->event_challenge)) {
            $leftLogos[] = $this->toDataUri(public_path('flow/fll_challenge_hs.png'));
        }
        $leftLogos = array_values(array_filter($leftLogos));

        $rightLogo = $this->toDataUri(public_path('flow/hot.png'));

        // Determine competition type text dynamically
        $competitionType = $this->getCompetitionTypeText($event);

        return [
            'leftLogos'       => $leftLogos,
            'centerTitleTop'  => 'FIRST LEGO League ' . $competitionType,
            'centerTitleMain' => trim(($event->name ?? '') . ' ' . $formattedDate),
            'rightLogo'       => $rightLogo,
        ];
    }

    /**
     * Determine the competition type text based on event configuration
     */
    private function getCompetitionTypeText(object $event): string
    {
        $hasExplore = !empty($event->event_explore);
        $hasChallenge = !empty($event->event_challenge);
        $level = (int)($event->level ?? 0);

        // Both Explore and Challenge Regio (level 1)
        if ($hasExplore && $hasChallenge && $level === 1) {
            return 'Ausstellung und Regionalwettbewerb';
        }

        // Only Explore
        if ($hasExplore && !$hasChallenge) {
            return 'Ausstellung';
        }

        // Only Challenge - check level
        if ($hasChallenge && !$hasExplore) {
            return match ($level) {
                1 => 'Regionalwettbewerb',
                2 => 'Qualifikationswettbewerb',
                3 => 'Finale',
                default => 'Wettbewerb',
            };
        }

        // Fallback
        return 'Wettbewerb';
    }

    /**
     * Build footer logos for PDF (copied from PdfLayoutService)
     */
    private function buildFooterLogos(int $eventId): array
    {
        $logos = DB::table('logo')
            ->join('event_logo', 'logo.id', '=', 'event_logo.logo')
            ->where('event_logo.event', $eventId)
            ->select('logo.path')
            ->get();

        $dataUris = [];
        foreach ($logos as $logo) {
            $path = storage_path('app/public/' . $logo->path);
            $uri  = $this->toDataUri($path);
            if ($uri) {
                $dataUris[] = $uri;
            }
        }

        return $dataUris;
    }

    /**
     * Convert file to data URI (copied from PdfLayoutService)
     */
    private function toDataUri(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $mime = mime_content_type($path) ?: 'image/png';
        $data = @file_get_contents($path);
        if ($data === false) {
            return null;
        }
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }

    /**
     * Get worker shifts for roles with differentiation_parameter
     */
    public function workerShifts(int $eventId)
    {
        // Get plan for this event
        $plan = DB::table('plan')
            ->where('event', $eventId)
            ->select('id')
            ->first();

        if (!$plan) {
            return response()->json(['error' => 'Kein Plan zum Event gefunden'], 404);
        }

        // Get roles with differentiation_parameter 'lane' or 'table'
        $roles = DB::table('m_role')
            ->whereIn('differentiation_parameter', ['lane', 'table'])
            ->select('id', 'name', 'differentiation_parameter')
            ->get();

        $shifts = [];

        foreach ($roles as $role) {
            // Fetch activities for this role
            $activities = collect($this->activityFetcher->fetchActivities(
                $plan->id,
                [$role->id],
                true,  // includeRooms
                false, // includeGroupMeta
                true,  // includeActivityMeta
                true,  // includeTeamNames
                true   // freeBlocks
            ));

            if ($activities->isEmpty()) {
                continue;
            }

            // Group activities by day
            $activitiesByDay = [];
            foreach ($activities as $activity) {
                $dayKey = \Carbon\Carbon::parse($activity->start_time)->format('Y-m-d');
                if (!isset($activitiesByDay[$dayKey])) {
                    $activitiesByDay[$dayKey] = [];
                }
                $activitiesByDay[$dayKey][] = $activity;
            }

            // Calculate shifts for each day
            $roleShifts = [];
            foreach ($activitiesByDay as $dayKey => $dayActivities) {
                $startTimes = collect($dayActivities)->pluck('start_time')->map(function($time) {
                    return \Carbon\Carbon::parse($time);
                });
                $endTimes = collect($dayActivities)->pluck('end_time')->map(function($time) {
                    return \Carbon\Carbon::parse($time);
                });

                $earliestStart = $startTimes->min();
                $latestEnd = $endTimes->max();

                $roleShifts[] = [
                    'day' => $dayKey,
                    'start' => $earliestStart->format('H:i'),
                    'end' => $latestEnd->format('H:i')
                ];
            }

            $shifts[] = [
                'role_name' => $role->name,
                'shifts' => $roleShifts
            ];
        }

        return response()->json(['shifts' => $shifts]);
    }

    /**
     * Export room utilization as CSV
     * Groups activities by day, room, and activity_type, merging consecutive related activities
     */
    public function roomUtilizationCsv(int $eventId)
    {
        // Get plan for this event
        $plan = DB::table('plan')
            ->where('event', $eventId)
            ->select('id')
            ->first();

        if (!$plan) {
            return response()->json(['error' => 'Kein Plan zum Event gefunden'], 404);
        }

        // Fetch activities with room data
        $activities = $this->activityFetcher->fetchActivities(
            $plan->id,
            [],                 // All roles
            true,              // includeRooms
            true,              // includeGroupMeta (for activity_type)
            false,             // includeActivityMeta
            false,             // includeTeamNames
            true               // freeBlocks
        );

        // Filter: only activities with rooms
        $activities = collect($activities)->filter(function ($a) {
            return !empty($a->room_name) || !empty($a->room_id);
        });

        if ($activities->isEmpty()) {
            return response()->json(['error' => 'Keine Aktivitäten mit Räumen gefunden'], 404);
        }

        // Group activities for merging
        $grouped = $this->groupActivitiesForUtilization($activities);

        // Generate CSV
        $csv = $this->generateRoomUtilizationCsv($grouped);

        // Return CSV response - format matches PDF exports
        $formattedDate = date('Y-m-d');
        $filename = "FLOW_Raumnutzung_({$formattedDate}).csv";
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Group activities for room utilization
     * Groups by day -> room -> activity_type, merging consecutive activities
     */
    private function groupActivitiesForUtilization($activities): array
    {
        // First, group by day and room
        $byDayRoom = $activities->groupBy(function ($a) {
            $startDate = \Carbon\Carbon::parse($a->start_time)->format('Y-m-d');
            $roomKey = $a->room_name ?? ('Room-' . $a->room_id);
            $roomSequence = $a->room_sequence ?? 9999; // Put rooms without sequence at the end
            return $startDate . '|' . str_pad($roomSequence, 10, '0', STR_PAD_LEFT) . '|' . $roomKey;
        });

        $result = [];

        foreach ($byDayRoom as $dayRoomKey => $dayRoomActivities) {
            [$date, $roomSequence, $roomName] = explode('|', $dayRoomKey, 3);
            
            // Sort activities by start time
            $sorted = $dayRoomActivities->sortBy('start_time')->values();

            // Group by activity_type (parent type from m_activity_type)
            $byType = $sorted->groupBy(function ($a) {
                // Get parent activity_type ID (m_activity_type.id)
                return $a->activity_type_id ?? $a->activity_type_group ?? 'unknown';
            });

            // For each activity_type group, merge consecutive activities
            foreach ($byType as $typeId => $typeActivities) {
                $merged = $this->mergeConsecutiveActivities($typeActivities);
                
                foreach ($merged as $block) {
                    $result[] = [
                        'day' => $date,
                        'room' => $roomName,
                        'activity_type' => $typeActivities->first()->activity_type_name ?? $typeActivities->first()->group_atd_name ?? 'Unbekannt',
                        'start_time' => $block['start']->format('Y-m-d H:i:s'),
                        'end_time' => $block['end']->format('Y-m-d H:i:s'),
                        'duration_minutes' => $block['duration'],
                    ];
                }
            }
        }

        // Sort: day first, room sequence second, room name third, start_time fourth
        // Get room sequences for sorting
        $roomSequences = [];
        foreach ($activities as $activity) {
            $roomKey = $activity->room_name ?? ('Room-' . $activity->room_id);
            if (!isset($roomSequences[$roomKey])) {
                $roomSequences[$roomKey] = $activity->room_sequence ?? 9999;
            }
        }
        
        usort($result, function ($a, $b) use ($roomSequences) {
            if ($a['day'] !== $b['day']) {
                return $a['day'] <=> $b['day'];
            }
            // Sort by room sequence if available
            $seqA = $roomSequences[$a['room']] ?? 9999;
            $seqB = $roomSequences[$b['room']] ?? 9999;
            if ($seqA !== $seqB) {
                return $seqA <=> $seqB;
            }
            // Fallback to room name if sequence is same
            if ($a['room'] !== $b['room']) {
                return strcmp($a['room'], $b['room']);
            }
            return strcmp($a['start_time'], $b['start_time']);
        });

        return $result;
    }

    /**
     * Merge consecutive activities of the same type
     * Merges activities that are consecutive or have short breaks (<= 15 minutes)
     */
    private function mergeConsecutiveActivities($activities): array
    {
        if ($activities->isEmpty()) {
            return [];
        }

        $sorted = $activities->sortBy('start_time')->values();
        $merged = [];
        $currentBlock = null;

        foreach ($sorted as $activity) {
            $start = \Carbon\Carbon::parse($activity->start_time);
            $end = \Carbon\Carbon::parse($activity->end_time);

            if ($currentBlock === null) {
                // Start new block
                $currentBlock = [
                    'start' => $start,
                    'end' => $end,
                ];
            } else {
                // Check if this activity is consecutive (within 15 minutes)
                $gap = $start->diffInMinutes($currentBlock['end']);
                
                if ($gap <= 15) {
                    // Merge: extend end time if needed
                    if ($end->gt($currentBlock['end'])) {
                        $currentBlock['end'] = $end;
                    }
                } else {
                    // Gap too large: save current block and start new one
                    $merged[] = [
                        'start' => $currentBlock['start'],
                        'end' => $currentBlock['end'],
                        'duration' => $currentBlock['start']->diffInMinutes($currentBlock['end']),
                    ];
                    $currentBlock = [
                        'start' => $start,
                        'end' => $end,
                    ];
                }
            }
        }

        // Save last block
        if ($currentBlock !== null) {
            $merged[] = [
                'start' => $currentBlock['start'],
                'end' => $currentBlock['end'],
                'duration' => $currentBlock['start']->diffInMinutes($currentBlock['end']),
            ];
        }

        return $merged;
    }

    /**
     * Generate CSV content from grouped activities
     */
    private function generateRoomUtilizationCsv(array $grouped): string
    {
        $output = fopen('php://temp', 'r+');
        
        // Add BOM for Excel UTF-8 support
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Header
        fputcsv($output, [
            'Tag',
            'Raum',
            'Aktivitätstyp',
            'Startzeit',
            'Endzeit',
            'Dauer (Minuten)',
        ], ';'); // Use semicolon for Excel compatibility in German locales

        // Data rows
        foreach ($grouped as $row) {
            $dayFormatted = \Carbon\Carbon::parse($row['day'])->format('d.m.Y');
            $startFormatted = \Carbon\Carbon::parse($row['start_time'])->format('H:i');
            $endFormatted = \Carbon\Carbon::parse($row['end_time'])->format('H:i');
            
            fputcsv($output, [
                $dayFormatted,
                $row['room'],
                $row['activity_type'],
                $startFormatted,
                $endFormatted,
                $row['duration_minutes'],
            ], ';');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    private function formatActivityLabel($activity): string
    {
        $base = $activity->activity_atd_name ?? ($activity->activity_name ?? '–');
        $code = $activity->group_activity_type_code ?? $activity->activity_type_code ?? null;

        static $roundLabels = null;

        if ($roundLabels === null) {
            $roundLabels = DB::table('m_activity_type_detail')
                ->whereIn('code', [
                    'r_test_round',
                    'r_round_1',
                    'r_round_2',
                    'r_round_3',
                    'r_round_4',
                    'r_final_16',
                    'r_final_8',
                    'r_final_4',
                    'r_final_2',
                ])
                ->pluck('name', 'code')
                ->toArray();
        }

        if ($code && isset($roundLabels[$code])) {
            return $roundLabels[$code];
        }

        return $base;
    }

    private function isFreeBlock($activity): bool
    {
        static $freeIds = null;

        if ($freeIds === null) {
            $freeIds = DB::table('m_activity_type_detail')
                ->where('code', 'like', '%free%')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();
        }

        if (empty($freeIds)) {
            return false;
        }

        $detailId = (int)($activity->activity_type_detail_id ?? $activity->activity_type_group ?? 0);
        return in_array($detailId, $freeIds, true);
    }
}
