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

            // Helper: Activity → Row
            $mapRow = function ($a) {
                $assign   = '–';
                $teamName = null;

                if (!empty($a->lane)) {
                    $assign   = 'Jury ' . $a->lane;
                    $teamName = $a->jury_team_name ?? null;
                } elseif (!empty($a->table_1)) {
                    $assign   = 'Tisch ' . $a->table_1;
                    $teamName = $a->table_1_team_name ?? null;
                } elseif (!empty($a->table_2)) {
                    $assign   = 'Tisch ' . $a->table_2;
                    $teamName = $a->table_2_team_name ?? null;
                }

                return [
                    'start_hm' => \Carbon\Carbon::parse($a->start_time)->format('H:i'),
                    'end_hm'   => \Carbon\Carbon::parse($a->end_time)->format('H:i'),
                    'activity' => $a->activity_atd_name ?? $a->activity_name ?? '—',
                    'assign'   => $assign,
                    'room'     => $a->room_name ?? $a->room_type_name ?? '–',
                    'team_id'  => $a->team,
                    'team_name'=> $teamName,
                ];
            };

            // Gruppieren nach Teamnummer
            $groups = $activities->groupBy('team');

            foreach ($groups as $teamId => $acts) {
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

                // Label bestimmen
                if ($teamId === null) {
                    $teamLabel = 'Alle Teams';
                } else {
                    $teamName = $firstAct->jury_team_name
                        ?? $firstAct->table_1_team_name
                        ?? $firstAct->table_2_team_name;

                    $teamLabel = 'Team ' . $teamId . ($teamName ? ' – ' . $teamName : '');
                }

                // Rowset einsortieren
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

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait');

        return $pdf->download("Plan_$planId.pdf");
    }
}