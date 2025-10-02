<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;

use App\Models\MRole;

use App\Services\ActivityFetcherService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

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

    // Rollen laden, die PDF-Export aktiviert haben
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
            includeActivityMeta: true,   // wichtig: liefert activity_first_program_name
            includeTeamNames: true,
            freeBlocks: false
        );

        if ($activities->isEmpty()) {
            continue;
        }

        Log::info("Rolle {$role->name}: " . $activities->count() . " Aktivitäten");

        // Differenzierung nach Parameter
        $roleTables = [];

        if ($role->differentiation_parameter === 'lane') {
            $groups = $activities->groupBy('lane');
            foreach ($groups as $lane => $acts) {
                $roleTables[] = [
                    'role' => $role->name,
                    'suffix' => $lane ? "Lane $lane" : null,
                    'program' => optional($acts->first())->activity_first_program_name ?? 'Alles',
                    'activities' => $acts->sortBy('start_time'),
                ];
            }

        } elseif ($role->differentiation_parameter === 'table') {
            $groups = $activities->groupBy('table_1_name');
            foreach ($groups as $tableName => $acts) {
                $roleTables[] = [
                    'role' => $role->name,
                    'suffix' => $tableName,
                    'program' => optional($acts->first())->activity_first_program_name ?? 'Alles',
                    'activities' => $acts->sortBy('start_time'),
                ];
            }

        } elseif ($role->differentiation_parameter === 'team') {
            $groups = $activities->groupBy('team');

            foreach ($groups as $teamId => $acts) {
                // Teamnummer & Name zusammensetzen
                if ($teamId === null) {
                    $teamLabel = "Team (unbekannt)";
                } else {
                    $teamName = null;
                    foreach ($acts as $a) {
                        $teamName = $a->jury_team_name ?? $a->table_1_team_name ?? $a->table_2_team_name;
                        if ($teamName) break;
                    }
                    $teamLabel = "Team $teamId" . ($teamName ? " – $teamName" : "");
                }

                $roleTables[] = [
                    'role' => $role->name,
                    'program' => optional($acts->first())->activity_first_program_name ?? 'Alles',
                    'teamLabel' => $teamLabel,   // eigener Key für Blade
                    'activities' => $acts->sortBy('start_time'),
                ];
            }
        

        } else {
            $roleTables[] = [
                'role' => $role->name,
                'suffix' => null,
                'program' => optional($activities->first())->activity_first_program_name ?? 'Alles',
                'activities' => $activities->sortBy('start_time'),
            ];
        }
        // Gruppierung nach Programmname (aus den Activities, nicht aus m_role)
        foreach ($roleTables as $table) {
            $programKey = $table['program'] ?? 'Alles';
            $programGroups[$programKey][] = $table;
        }
    }

    if (empty($programGroups)) {
        return response()->json(['error' => 'Keine Aktivitäten gefunden'], 404);
    }

    $html = view('pdf.plan_export', [
        'programGroups' => $programGroups
    ])->render();

    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
        ->setPaper('a4', 'portrait');

    return $pdf->download("Plan_$planId.pdf");
}

}