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

        $maxRowsPerPage = 18; // Anzahl Zeilen pro Seite    

        // PDF erzeugen
        $pdf = match ($type) {
            'rooms' => $this->roomSchedulePdf($plan->id, $maxRowsPerPage),
            'teams' => $this->teamSchedulePdf($plan->id, $maxRowsPerPage),
            'roles' => $this->roleSchedulePdf($plan->id, $maxRowsPerPage),
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
                    return sprintf("T%02d", $numInternal);
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

        // Jetzt EIN Layout drumherum bauen
        $layout = app(\App\Services\PdfLayoutService::class);
        $finalHtml = $layout->renderLayout($event, $html, 'FLOW Raumbeschilderung');

        // PDF im Querformat erzeugen
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($finalHtml, 'UTF-8')->setPaper('a4', 'landscape');

        return $pdf;
    }

    public function teamSchedulePdf(int $planId, $maxRowsPerPage = 10)
    {
        $fetcher = app(\App\Services\ActivityFetcherService::class);

        // 1) Explore (Role 3)
        $exploreActs = collect($fetcher->fetchActivities(
            $planId,
            [8],   // Explore-Teams
            true,  // includeRooms
            false, // includeGroupMeta
            true,  // includeActivityMeta (liefert activity_atd_name, activity_first_program_name, ...)
            true,  // includeTeamNames (jury_team_name, table_*_team_name)
            true   // freeBlocks
        ));

        // 2) Challenge (Role 8)
        $challengeActs = collect($fetcher->fetchActivities(
            $planId,
            [3], true, false, true, true, true
        ));

        // Event laden (für Layout + QR/Link)
        $event = DB::table('event')
            ->join('plan', 'plan.event', '=', 'event.id')
            ->where('plan.id', $planId)
            ->select('event.*')
            ->first();

        // Seiten je Programm
        $explorePages   = $this->buildExploreTeamPages($exploreActs);
        $challengePages = $this->buildChallengeTeamPages($challengeActs);

        // Explore zuerst, dann Challenge
        $pages = array_merge($explorePages, $challengePages);

        // HTML bauen
        $html = '';
        $lastIndex = count($pages) - 1;

        foreach ($pages as $idx => $page) {
            // innerhalb des Teams chronologisch sortieren
            $acts = $page['acts']->sortBy([
                ['start_time', 'asc'],
                ['end_time', 'asc'],
            ]);

            $rows = $acts->map(function ($a) {
                return [
                    'start'    => \Carbon\Carbon::parse($a->start_time)->format('H:i'),
                    'end'      => \Carbon\Carbon::parse($a->end_time)->format('H:i'),
                    'activity' => $a->activity_atd_name ?? ($a->activity_name ?? '–'),
                    'room'     => $a->room_name ?? '–',
                ];
            })->values()->all();

            // In Seitenblöcke teilen
            $chunks = array_chunk($rows, $maxRowsPerPage);
            $chunkCount = count($chunks);

            foreach ($chunks as $chunkIndex => $chunkRows) {
                $html .= view('pdf.content.team_schedule', [
                    'team'  => $page['label'], // z.B. "Explore 12 – RoboKids"
                    'rows'  => $chunkRows,
                    'event' => $event,
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
        // Teamnummern + Namen sammeln
        $teamNames = []; // [num => name]
        $teamHot   = []; // [num => team_number_hot]
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
                $label = sprintf("FLL Explore T%02d", $num);
            } else {
                $label = "FLL Explore –";
            }

            $pages[] = [
                'label' => $label,
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
        }
    }

    if (empty($teamSet)) {
        return [];
    }

    // Globale Acts = kein Team in allen 3 Feldern
    $globalActs = $acts->filter(function ($a) {
        return is_null($a->team) && is_null($a->table_1_team) && is_null($a->table_2_team);
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
            $label = sprintf("FLL Challenge T%02d", $num);
        } else {
            $label = "FLL Challenge –";
        }

        $pages[] = [
            'label' => $label,
            'acts'  => $ownActs->concat($globalActs),
        ];
    }

    return $pages;
}




    public function roleSchedulePdf(int $planId, $maxRowsPerPage = 10)
    {
        $fetcher = app(\App\Services\ActivityFetcherService::class);

        // === 1️⃣ EXPLORE Jury (Role 9) ===
        $exploreActs = $fetcher->fetchActivities($planId, [9], true, false, true, true, true);

        // === 2️⃣ CHALLENGE Jury (Role 4) ===
        $challengeJuryActs = $fetcher->fetchActivities($planId, [4], true, false, true, true, true);

        // === 3️⃣ CHALLENGE Robot Game – Referees (Role 5) ===
        $challengeRefActs = $fetcher->fetchActivities($planId, [5], true, false, true, true, true);

        // === 4️⃣ CHALLENGE Robot Check (Role 11) ===
        $challengeCheckActs = $fetcher->fetchActivities($planId, [11], true, false, true, true, true);

        // === Event laden ===
        $event = DB::table('event')
            ->join('plan', 'plan.event', '=', 'event.id')
            ->where('plan.id', $planId)
            ->select('event.*')
            ->first();

        /**
         * Hilfsfunktion: verteilt "allgemeine" Aktivitäten auf alle Gruppen
         */
        $distributeGeneric = function ($activities, $groupKey, $labelPrefix) {
            // Falls Robot Game → gemeinsame Tisch-ID erzeugen
            if (in_array($groupKey, ['table_1', 'table_2'])) {
                $expanded = collect();
                foreach ($activities as $a) {
                    $refEntries = [];

                    if (!empty($a->table_1)) {
                        $clone = clone $a;
                        $clone->ref_table = $a->table_1;
                        $clone->ref_table_name = $a->table_1_name;
                        $refEntries[] = $clone;
                    }

                    if (!empty($a->table_2)) {
                        $clone = clone $a;
                        $clone->ref_table = $a->table_2;
                        $clone->ref_table_name = $a->table_2_name;
                        $refEntries[] = $clone;
                    }

                    if (empty($refEntries)) {
                        $refEntries[] = $a;
                    }

                    foreach ($refEntries as $entry) {
                        $expanded->push($entry);
                    }
                }
                $activities = $expanded;
                $groupKey = 'ref_table';
            }

            $withKey = collect($activities)->filter(fn($a) => !empty($a->{$groupKey}));
            $withoutKey = collect($activities)->filter(fn($a) => empty($a->{$groupKey}));

            // alle vorhandenen Keys sammeln
            $allKeys = $withKey->pluck($groupKey)->unique()->values();

            // allgemeine Aktivitäten duplizieren
            foreach ($withoutKey as $generic) {
                foreach ($allKeys as $keyValue) {
                    $clone = clone $generic;
                    $clone->{$groupKey} = $keyValue;
                    $withKey->push($clone);
                }
            }

            // Gruppieren nach Schlüssel
            return $withKey->groupBy(function ($a) use ($groupKey, $labelPrefix) {
                $val = $a->{$groupKey};
                if ($groupKey === 'lane') {
                    return "{$labelPrefix} {$val}";
                } elseif ($groupKey === 'ref_table') {
                    return "{$labelPrefix} " . ($a->ref_table_name ?? "Tisch {$val}");
                }
                return "{$labelPrefix} – {$val}";
            });
        };

        // === Gruppieren & Duplizieren ===
        $exploreGrouped       = $distributeGeneric($exploreActs, 'lane', 'FLL Explore Gutachter:innen-Gruppe');
        $challengeJuryGrouped = $distributeGeneric($challengeJuryActs, 'lane', 'FLL Challenge Jury-Gruppe');
        $challengeRefGrouped  = $distributeGeneric($challengeRefActs, 'table_1', 'FLL Challenge Schiedsrichter:innen ');
        $challengeCheckGrouped= $distributeGeneric($challengeCheckActs, 'table_2', 'FLL Challenge Robot-Check für ');

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
                            return sprintf('T%02d', (int)$num);
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


}
