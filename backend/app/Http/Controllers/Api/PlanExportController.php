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

    // Nur Rollen mit PDF-Export, sortiert
    $roles = MRole::where('pdf_export', true)
        ->orderBy('first_program')
        ->orderBy('sequence')
        ->get();

    $programGroups = [];

    foreach ($roles as $role) {
        // Nur Rollen mit Differenzierung "team" berücksichtigen
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

        Log::info("Rolle {$role->name}: " . $activities->count() . " Aktivitäten");

        // Aufsplitten nach Teamnummer
        $groups = $activities->groupBy('team');

        $roleTables = [];

        // Zuerst: Aktivitäten ohne Teamnummer
        if ($groups->has(null)) {
            $acts = $groups->get(null)->sortBy('start_time');
            $roleTables[] = [
                'role'       => $role->name,
                'program'    => optional($acts->first())->activity_first_program_name ?? 'Alles',
                'teamLabel'  => 'Ohne Team',
                'activities' => $acts,
            ];
        }

        // Jetzt: Aktivitäten mit Teamnummer
        foreach ($groups->except([null])->sortKeys() as $teamId => $acts) {
            $teamName = null;
            foreach ($acts as $a) {
                $teamName = $a->jury_team_name ?? $a->table_1_team_name ?? $a->table_2_team_name;
                if ($teamName) break;
            }

            $teamLabel = "Team $teamId" . ($teamName ? " – $teamName" : "");

            $roleTables[] = [
                'role'       => $role->name,
                'program'    => optional($acts->first())->activity_first_program_name ?? 'Alles',
                'teamLabel'  => $teamLabel,
                'activities' => $acts->sortBy('start_time'),
            ];
        }

        // Gruppierung nach Program
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