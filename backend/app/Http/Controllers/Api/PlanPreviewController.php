<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PreviewMatrixService;
use App\Services\ActivityFetcherService;
use Illuminate\Support\Facades\DB;


class PlanPreviewController extends Controller
{
    public function __construct(private ActivityFetcherService $activities) {}

    public function previewTeams(int $plan, PreviewMatrixService $builder)
    {
        // Team-Rollen ermitteln (nur für Programme, die in der Preview-Matrix relevant sind)
        $teamRoleIds = DB::table('m_role')
            ->whereNotNull('first_program')
            ->where('preview_matrix', 1)
            ->where('differentiation_parameter', 'team')
            ->pluck('id')
            ->all();

        // Activities gefiltert nach diesen Rollen laden
        $activities = $this->activities->fetchActivities(
            plan: $plan,
            roles: $teamRoleIds,
            freeBlocks: false
        );

        if ($activities->isEmpty()) {
            // Return stable headers so the frontend can render an empty grid
            return [
                ['key' => 'time', 'title' => 'Zeit'],
            ];
        }

        $matrix = $builder->buildTeamsMatrix($activities);
        return response()->json($matrix);
    }

    public function previewRooms(int $plan, PreviewMatrixService $builder)
    {
        $activities = $this->activities->fetchActivities(
            plan: $plan,
            includeRooms: true,
            freeBlocks: false
        );

        if ($activities->isEmpty()) {
            return [['key' => 'time', 'title' => 'Zeit']];
        }

        return response()->json($builder->buildRoomsMatrix($activities));
}

    public function previewRoles(int $plan, PreviewMatrixService $builder)
    {
        // Nur lane/table-Rollen für Preview
        $roles = DB::table('m_role')
            ->whereNotNull('first_program')
            ->where('preview_matrix', 1)
            ->whereIn('differentiation_parameter', ['lane','table'])
            ->orderBy('first_program')
            ->orderBy('sequence')
            ->get();

        $activities = $this->activities->fetchActivities(
            plan: $plan,
            roles: $roles->pluck('id')->all(),
            freeBlocks: false
        );

        if ($activities->isEmpty()) {
            return [ ['key' => 'time', 'title' => 'Zeit'] ];
        }

        $matrix = $builder->buildRolesMatrix($activities, $roles);
        return response()->json($matrix);
    }

}