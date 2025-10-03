<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRole;
use App\Services\ActivityFetcherService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PlanExportController extends Controller
{
    private ActivityFetcherService $activityFetcher;

    public function __construct(ActivityFetcherService $activityFetcher)
    {
        $this->activityFetcher = $activityFetcher;
    }

    public function exportPdf(int $planId)
    {
        Log::info("Starte PDF-Export für Plan $planId");

        // Nur Rollen mit PDF-Export, sortiert
        $roles = MRole::where('pdf_export', true)
            ->orderBy('first_program')
            ->orderBy('sequence')
            ->get();

        $programGroups = [];

        foreach ($roles as $role) {
            if ($role->differentiation_parameter !== 'team') {
                continue;
            }

            $activities = $this->activityFetcher->fetchActivities(
                plan: $planId,
                roles: [$role->id],
                includeRooms: true,
                includeGroupMeta: false,
                includeActivityMeta: true,
                includeTeamNames: true,
                freeBlocks: false
            );

            if ($activities->isEmpty()) {
                continue;
            }

            // === Schritt 1: Activities "entfalten" (Lane/Table1/Table2 einzeln) ===
            $expanded = collect();
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

                // Falls gar kein Team dran hängt → neutrale Zeile
                if (empty($a->lane) && empty($a->table_1) && empty($a->table_2)) {
                    $clone = clone $a;
                    $clone->team      = null;
                    $clone->team_name = null;
                    $clone->assign    = '–';
                    $expanded->push($clone);
                }
            }

            // === Schritt 2: Nach Team gruppieren ===
            $groups = $expanded->groupBy('team');

            // === Schritt 3: Map-Funktion für Tabellenzeilen ===
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
                $acts = $acts->sortBy('start_time');
                $firstAct = $acts->first();
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

                // Teamlabel bestimmen
                if ($teamId === null) {
                    $teamLabel = 'Alle Teams';
                } else {
                    $teamName = $acts->first()->team_name ?? null;
                    $teamLabel = 'Team ' . $teamId . ($teamName ? ' – ' . $teamName : '');
                }

                $programGroups[$programName][$role->id]['teams'][] = [
                    'teamLabel' => $teamLabel,
                    'rows'      => $acts->map($mapRow)->values()->all(),
                ];
            }



        }

        if (empty($programGroups)) {
            return response()->json(['error' => 'Keine Aktivitäten gefunden'], 404);
        }

        $html = view('pdf.plan_export', [
            'programGroups' => $programGroups,
        ])->render();

        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait');

        return $pdf->download("Plan_$planId.pdf");
    }
}