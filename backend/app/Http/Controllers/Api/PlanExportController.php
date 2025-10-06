<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRole;
use App\Models\Plan;
use App\Models\Event;
use App\Services\ActivityFetcherService;
use Barryvdh\DomPDF\Facade\Pdf;
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

    public function download(string $type, int $eventId)
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

        // PDF erzeugen
        $pdfResponse = match ($type) {
            'rooms' => $this->roomSchedulePdf($plan->id),
            // 'teams' => $this->teamSchedulePdf($plan->id),
            // 'roles' => $this->roleSchedulePdf($plan->id),
            'full'  => $this->fullSchedulePdf($plan->id),
            default => response()->json(['error' => 'Unknown type'], 400),
        };

        // Dateiname abhängig vom Typ
        $names = [
            'rooms' => 'Räume',
            'teams' => 'Teams',
            'roles' => 'Rollen',
            'full'  => 'Gesamtplan',
        ];

        $name = $names[$type] ?? ucfirst($type);

        return $pdfResponse->download("FLOW_{$name}_({$formattedDate}).pdf");
    }


    public function fullSchedulePdf(int $planId)
    {
        Log::info("Starte PDF-Export für Plan $planId");

        $roles = MRole::where('pdf_export', true)
            ->orderBy('first_program')
            ->orderBy('sequence')
            ->get();

        $programGroups = [];

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

            switch ($role->differentiation_parameter) {
                case 'team':
                    $this->buildTeamBlock($programGroups, $activities, $role);
                    break;

                case 'lane':
                    $this->buildLaneBlock($programGroups, $activities, $role);
                    break;

                case 'table':
                    $this->buildTableBlock($programGroups, $activities, $role);
                    break;

                default:
                    $this->buildSimpleBlock($programGroups, $activities, $role);
                    break;
            }
        }

        if (empty($programGroups)) {
            return response()->json(['error' => 'Keine Aktivitäten gefunden'], 404);
        }

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
    private function buildTeamBlock(array &$programGroups, $activities, $role): void
    {
        // === Schritt 1: Activities entfalten (Lane/Table1/Table2 einzeln) ===
        $expanded   = collect();
        $neutral    = collect(); // neutrale Zeilen merken
        foreach ($activities as $a) {
            if (!empty($a->lane) && !empty($a->team)) {
                $clone = clone $a;
                $clone->team      = $a->team;
                $clone->team_name = $a->jury_team_name;
                $clone->assign    = 'Jury ' . $a->lane;
                $expanded->push($clone);
            }
            if (!empty($a->table_1) && !empty($a->table_1_team)) {
                $clone = clone $a;
                $clone->team      = $a->table_1_team;
                $clone->team_name = $a->table_1_team_name;
                $clone->assign    = 'Tisch ' . $a->table_1;
                $expanded->push($clone);
            }
            if (!empty($a->table_2) && !empty($a->table_2_team)) {
                $clone = clone $a;
                $clone->team      = $a->table_2_team;
                $clone->team_name = $a->table_2_team_name;
                $clone->assign    = 'Tisch ' . $a->table_2;
                $expanded->push($clone);
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

        // --- Sortierung nach Team-ID ---
        foreach ($groups->sortKeys() as $teamId => $acts) {
            // neutrales reingeben
            $allActs = $acts->concat($neutral)->sortBy('start_time');
            $firstAct = $allActs->first();
            $programName = $firstAct->activity_first_program_name ?? 'Alles';

            if (!isset($programGroups[$programName])) {
                $programGroups[$programName] = [];
            }
            if (!isset($programGroups[$programName][$role->id])) {
                $programGroups[$programName][$role->id] = [
                    'role'  => $role->name,
                    'teams' => []
                ];
            }

            $teamName  = $acts->first()->team_name ?? null;
            $teamLabel = 'Team ' . $teamId . ($teamName ? ' – ' . $teamName : '');

            $programGroups[$programName][$role->id]['teams'][] = [
                'teamLabel' => $teamLabel,
                'rows'      => $allActs->map($mapRow)->values()->all(),
            ];
        }
    }

    /**
     * Block für Lane-Differenzierung (Dummy)
     */

    private function buildLaneBlock(array &$programGroups, $activities, $role): void
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

        // === Schritt 4: Iteration über Lanes ===
        foreach ($groups->sortKeys() as $laneId => $acts) {
            $allActs     = $acts->concat($neutral)->sortBy('start_time');
            $firstAct    = $allActs->first();
            $programName = $firstAct->activity_first_program_name ?? 'Alles';

            if (!isset($programGroups[$programName])) {
                $programGroups[$programName] = [];
            }
            if (!isset($programGroups[$programName][$role->id])) {
                $programGroups[$programName][$role->id] = [
                    'role'  => $role->name,
                    'lanes' => []
                ];
            }

            $juryLabel = 'Gruppe ' . $laneId;

            $programGroups[$programName][$role->id]['lanes'][] = [
                'juryLabel' => $juryLabel,
                'rows'      => $allActs->map($mapRow)->values()->all(),
            ];
        }
    }

    /**
     * Block für Table-Differenzierung
     */
    private function buildTableBlock(array &$programGroups, $activities, $role): void
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
            $programName = $firstAct->activity_first_program_name ?? 'Alles';

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
    private function buildSimpleBlock(array &$programGroups, $activities, $role): void
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
        $programName = $firstAct->activity_first_program_name ?? 'Alles';

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


    public function roomSchedulePdf(int $planId)
    {
        $activities = app(\App\Services\ActivityFetcherService::class)
            ->fetchActivities(
                $planId,
                [6, 10, 14],   // Rollen
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
            $acts = $grouped->get($room)->sortBy('start_time');

            $rows = $acts->map(function ($a) {
                // Teams aus den fetcher-Feldern zusammensetzen
                $teamParts = [];

                // Jury (Lane)
                if (!empty($a->lane) && $a->team !== null) {
                    // Name wenn vorhanden, sonst Nummer
                    $teamParts[] = !empty($a->jury_team_name) ? $a->jury_team_name : (string)$a->team;
                }

                // Tisch 1
                if (!empty($a->table_1) && $a->table_1_team !== null) {
                    $teamParts[] = !empty($a->table_1_team_name) ? $a->table_1_team_name : (string)$a->table_1_team;
                }

                // Tisch 2
                if (!empty($a->table_2) && $a->table_2_team !== null) {
                    $teamParts[] = !empty($a->table_2_team_name) ? $a->table_2_team_name : (string)$a->table_2_team;
                }

                $teamDisplay = count($teamParts) ? implode(' / ', $teamParts) : '–';

                return [
                    'start'    => \Carbon\Carbon::parse($a->start_time)->format('H:i'),
                    'end'      => \Carbon\Carbon::parse($a->end_time)->format('H:i'),
                    'activity' => $a->activity_atd_name ?? ($a->activity_name ?? '–'),
                    'team'     => $teamDisplay,
                ];
            })->values()->all();

            // Teil-HTML für den Raum (ohne eigene Header/Footer)
            $html .= view('pdf.content.room_schedule', [
                'room' => $room,
                'rows' => $rows,
                'event' => $event,
            ])->render();

            // Seitenumbruch zwischen Räumen (aber nicht nach dem letzten)
            if ($idx !== $lastIndex) {
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
}
