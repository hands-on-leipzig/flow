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

        $maxRowsPerPage = 18; // Anzahl Zeilen pro Seite    

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
                $clone->assign    = 'Tisch ' . $a->table_1;
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
                $clone->assign    = 'Tisch ' . $a->table_2;
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
                'activity' => $a->activity_atd_name ?? $a->activity_name ?? '—',
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
            
            // Build label: "TeamName (HotNumber) - Teamraum RoomName"
            $label = '';
            
            // Team name part
            if ($teamName && $teamHot) {
                $label = "{$teamName} ({$teamHot})";
            } elseif ($teamName) {
                $label = $teamName;
            } else {
                $label = "!Platzhalter, weil nicht genügend Teams angemeldet sind!";
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
                $clone->assign    = 'Jury ' . $a->lane;
                $expanded->push($clone);
            }

            // Falls keine Lane → neutral
            if (empty($a->lane)) {
                $clone = clone $a;
                $clone->lane      = null;
                $clone->team_id   = null;
                $clone->team_name = null;
                $clone->assign    = '–';
                $neutral->push($clone);
            }
        }

        // === Schritt 2: Gruppieren nach Lane ===
        $groups = $expanded->groupBy('lane');

        // === Schritt 3: Map-Funktion ===
        $mapRow = function ($a) {
            $teamLabel = $a->team_id
                ? ('Team ' . $a->team_id . ($a->team_name ? ' – ' . $a->team_name : ''))
                : '–';

            return [
                'start_hm' => Carbon::parse($a->start_time)->format('H:i'),
                'end_hm'   => Carbon::parse($a->end_time)->format('H:i'),
                'activity' => $a->activity_atd_name ?? $a->activity_name ?? '—',
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
                $roomData = DB::table('room_type_room as rtr')
                    ->join('room as r', 'rtr.room', '=', 'r.id')
                    ->join('m_room_type as mrt', 'rtr.room_type', '=', 'mrt.id')
                    ->where('rtr.event', $eventId)
                    ->whereIn('mrt.id', [2, 3, 4, 5, 6]) // Jurybewertung 1-5 (corresponds to lanes 1-5)
                    ->select('mrt.id as room_type_id', 'r.name as room_name')
                    ->get();
                
                // Map room_type_id to lane number (Jurybewertung 1 = lane 1, etc.)
                foreach ($roomData as $rd) {
                    $laneNumber = $rd->room_type_id - 1; // Jurybewertung 1 (ID 2) = lane 1
                    $laneRooms->put($laneNumber, $rd->room_name);
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
                $clone->assign     = 'Tisch ' . $a->table_1;
                $expanded->push($clone);
            }
            if (!empty($a->table_2) && !empty($a->table_2_team)) {
                $clone = clone $a;
                $clone->table_id   = $a->table_2;
                $clone->team_id    = $a->table_2_team;
                $clone->team_name  = $a->table_2_team_name;
                $clone->assign     = 'Tisch ' . $a->table_2;
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
            return [
                'start_hm'  => Carbon::parse($a->start_time)->format('H:i'),
                'end_hm'    => Carbon::parse($a->end_time)->format('H:i'),
                'activity'  => $a->activity_atd_name ?? $a->activity_name ?? '—',
                'teamLabel' => 'Team ' . $a->team_id . ($a->team_name ? ' – ' . $a->team_name : ''),
                'assign'    => $a->assign,
                'room'      => $a->room_name ?? $a->room_type_name ?? '–',
            ];
        };

        // Schritt 4: In ProgramGroups einsortieren
        foreach ($groups->sortKeys() as $tableId => $acts) {
            $acts = $acts->sortBy('start_time');
            $firstAct = $acts->first();
            $programName = $programNameOverride ?? ($firstAct->activity_first_program_name ?? 'Alles');

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
                'tableLabel' => 'Tisch ' . $tableId,
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
            $teamLabel = null;
            if (!empty($a->team)) {
                $teamLabel = 'Team ' . $a->team;
            }
            if (!empty($a->jury_team_name)) {
                $teamLabel = 'Team ' . $a->team . ' – ' . $a->jury_team_name;
            }
            if (!empty($a->table_1_team_name)) {
                $teamLabel = 'Team ' . $a->table_1_team . ' – ' . $a->table_1_team_name;
            }
            if (!empty($a->table_2_team_name)) {
                $teamLabel = 'Team ' . $a->table_2_team . ' – ' . $a->table_2_team_name;
            }

            // Assignment (Jury/Tisch/-)
            $assign = '–';
            if (!empty($a->lane)) {
                $assign = 'Jury ' . $a->lane;
            } elseif (!empty($a->table_1)) {
                $assign = 'Tisch ' . $a->table_1;
            } elseif (!empty($a->table_2)) {
                $assign = 'Tisch ' . $a->table_2;
            }

            return [
                'start_hm'  => \Carbon\Carbon::parse($a->start_time)->format('H:i'),
                'end_hm'    => \Carbon\Carbon::parse($a->end_time)->format('H:i'),
                'activity'  => $a->activity_atd_name ?? $a->activity_name ?? '—',
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
            $teamLabel = null;
            if (!empty($a->team)) {
                $teamLabel = 'Team ' . $a->team;
            }
            if (!empty($a->jury_team_name)) {
                $teamLabel = 'Team ' . $a->team . ' – ' . $a->jury_team_name;
            }
            if (!empty($a->table_1_team_name)) {
                $teamLabel = 'Team ' . $a->table_1_team . ' – ' . $a->table_1_team_name;
            }
            if (!empty($a->table_2_team_name)) {
                $teamLabel = 'Team ' . $a->table_2_team . ' – ' . $a->table_2_team_name;
            }

            // Assignment (Jury/Tisch/-)
            $assign = '–';
            if (!empty($a->lane)) {
                $assign = 'Jury ' . $a->lane;
            } elseif (!empty($a->table_1)) {
                $assign = 'Tisch ' . $a->table_1;
            } elseif (!empty($a->table_2)) {
                $assign = 'Tisch ' . $a->table_2;
            }

            return [
                'start_hm'  => \Carbon\Carbon::parse($a->start_time)->format('H:i'),
                'end_hm'    => \Carbon\Carbon::parse($a->end_time)->format('H:i'),
                'activity'  => $a->activity_atd_name ?? $a->activity_name ?? '—',
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


    public function roomSchedulePdf(int $planId, $maxRowsPerPage = 10)
    {
        $activities = app(\App\Services\ActivityFetcherService::class)
            ->fetchActivities(
                $planId,
                [6, 10, 14],   // Rollen: Publikum E, C und generisch
                true,          // includeRooms
                false,         // includeGroupMeta
                true,          // includeActivityMeta
                true,          // includeTeamNames
                true           // freeBlocks
            );

        // Nur Aktivitäten mit echtem Raum
        $activities = collect($activities)->filter(fn($a) => !empty($a->room_name) || !empty($a->room_id));

        // Gruppieren nach Raum
        $grouped = $activities->groupBy(fn($a) => $a->room_name ?? $a->room_id);

        // Event laden
        $event = DB::table('event')
            ->join('plan', 'plan.event', '=', 'event.id')
            ->where('plan.id', $planId)
            ->select('event.*')
            ->first();


        $html = '';

        $roomKeys  = $grouped->keys()->values();
        $lastIndex = $roomKeys->count() - 1;

        foreach ($roomKeys as $idx => $room) {
            $acts = $grouped->get($room)->sortBy([
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
                'activity' => $a->activity_atd_name ?? ($a->activity_name ?? '–'),
                'team'     => $teamDisplay,
                // 🔸 Icons vorbereiten (Logik bleibt hier, Blade rendert nur)
                'is_explore'    => in_array($a->activity_first_program_id, [FirstProgram::JOINT->value, FirstProgram::EXPLORE->value]),
                'is_challenge'  => in_array($a->activity_first_program_id, [FirstProgram::JOINT->value, FirstProgram::CHALLENGE->value]),
            ];
        })->values()->all();

            // Tabelle in mehrere Seiten splitten
            $chunks = array_chunk($rows, $maxRowsPerPage);
            $chunkCount = count($chunks);

            foreach ($chunks as $chunkIndex => $chunkRows) {
                $html .= view('pdf.content.room_schedule', [
                    'room'  => $room,
                    'rows'  => $chunkRows,
                    'event' => $event,
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
    // Raumdetails aus room-Tabelle
    $rooms = DB::table('room')
        ->whereIn('id', $prepRooms)
        ->select('id', 'name')
        ->orderBy('name')
        ->get();

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

        // Zeilen für Tabelle aufbauen
        $rows = $teams->map(function ($t) {
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

        // Seitenumbruch nach jeder Raumseite
        $html .= '<div style="page-break-before: always;"></div>';
    }
}



        // Jetzt EIN Layout drumherum bauen
        $layout = app(\App\Services\PdfLayoutService::class);
        $finalHtml = $layout->renderLayout($event, $html, 'FLOW Raumbeschilderung');

        // PDF im Querformat erzeugen
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($finalHtml, 'UTF-8')->setPaper('a4', 'landscape');

        return $pdf;
    }

    public function teamSchedulePdf(int $planId, array $programIds = [], $maxRowsPerPage = 10)
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
                false, // includeGroupMeta
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
                [3], true, false, true, true, true
            ));
            $challengePages = $this->buildChallengeTeamPages($challengeActs);
        }

        // Event laden (für Layout + QR/Link)
        $event = DB::table('event')
            ->join('plan', 'plan.event', '=', 'event.id')
            ->where('plan.id', $planId)
            ->select('event.*')
            ->first();

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
                    'activity' => $a->activity_atd_name ?? ($a->activity_name ?? '–'),
                    'room'     => $roomDisplay,
                ];
            })->values()->all();

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
                        ->select('name', 'navigation_instruction')
                        ->first();

                    if ($roomData && $roomData->name) {
                        $teamRoomName = $roomData->name;
                    }
                }
            }
            
            // Collect unique rooms with navigation for legend
            $roomsWithNav = [];
            
            // Add team's assigned room if it has navigation
            if ($roomData && !empty($roomData->name) && !empty($roomData->navigation_instruction)) {
                $roomsWithNav[$roomData->name] = $roomData->navigation_instruction;
            }
            
            // Add rooms from activities
            foreach ($acts as $a) {
                if (!empty($a->room_name) && !empty($a->room_navigation)) {
                    $roomsWithNav[$a->room_name] = $a->room_navigation;
                }
            }

            // ➕ Zusatzzeile "Teambereich"
            array_unshift($rows, [
                'start'    => '',
                'end'      => '',
                'activity' => 'Teambereich',
                'room'     => $teamRoomName,
            ]);
            
            // In Seitenblöcke teilen
            $chunks = array_chunk($rows, $maxRowsPerPage);
            $chunkCount = count($chunks);

            foreach ($chunks as $chunkIndex => $chunkRows) {
                $html .= view('pdf.content.team_schedule', [
                    'team'  => $page['label'], // z.B. "Explore 12 – RoboKids"
                    'rows'  => $chunkRows,
                    'event' => $event,
                    'roomsWithNav' => $chunkIndex === 0 ? $roomsWithNav : [], // Only on first chunk
                ])->render();

                // Seitenumbruch nach jedem Chunk, außer dem letzten der letzten Seite
                $isLastChunk = ($idx === $lastIndex) && ($chunkIndex === $chunkCount - 1);
                if (!$isLastChunk) {
                    $html .= '<div style="page-break-before: always;"></div>';
                }
            }
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




    public function roleSchedulePdf(int $planId, array $roleIds = [], $maxRowsPerPage = 10)
    {
        $fetcher = app(\App\Services\ActivityFetcherService::class);

        // If no role IDs provided, use default set (backward compatibility)
        if (empty($roleIds)) {
            $roleIds = [9, 4, 5, 11]; // EXPLORE Jury, CHALLENGE Jury, Referees, Robot Check
        }

        // Fetch activities for all selected roles and tag them with their role_id
        $allActivities = collect();
        foreach ($roleIds as $roleId) {
            $acts = $fetcher->fetchActivities($planId, [$roleId], true, false, true, true, true);
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

        // === Event laden ===
        $event = DB::table('event')
            ->join('plan', 'plan.event', '=', 'event.id')
            ->where('plan.id', $planId)
            ->select('event.*')
            ->first();

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

        // === Zusammenführen, sortiert nach Program-Logik ===
        $sections = collect()
            ->merge($exploreGrouped->sortKeys())
            ->merge($challengeJuryGrouped->sortKeys())
            ->merge($challengeRefGrouped->sortKeys())
           ->merge($challengeCheckGrouped->sortKeys());

        // === Rendern aller Abschnitte ===
        $html = '';
        $keys = $sections->keys()->values();
        $last = $keys->count() - 1;

        foreach ($keys as $i => $key) {
            $acts = $sections[$key]->sortBy([
                ['start_time', 'asc'],
                ['end_time', 'asc'],
            ]);

            // Collect unique rooms with navigation for legend
            $roomsWithNav = [];
            foreach ($acts as $a) {
                if (!empty($a->room_name) && !empty($a->room_navigation)) {
                    $roomsWithNav[$a->room_name] = $a->room_navigation;
                }
            }
            
            $rows = $acts->map(fn($a) => [
                'start'    => \Carbon\Carbon::parse($a->start_time)->format('H:i'),
                'end'      => \Carbon\Carbon::parse($a->end_time)->format('H:i'),
                'activity' => $a->activity_atd_name ?? $a->activity_name ?? '–',
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
            ])->values()->all();

            // Teile das Array in Seitenblöcke
            $chunks = array_chunk($rows, $maxRowsPerPage);
            $chunkCount = count($chunks);

            foreach ($chunks as $chunkIndex => $chunkRows) {
                $html .= view('pdf.content.role_schedule', [
                    'title' => $key,
                    'rows'  => $chunkRows,
                    'event' => $event,
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


}
