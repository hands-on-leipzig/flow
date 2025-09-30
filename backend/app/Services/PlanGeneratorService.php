<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Support\PlanParameter;
use App\Support\Helpers;
use App\Jobs\GeneratePlanJob;

class PlanGeneratorService
{
    public function isSupported(int $planId): bool
    {
        // Plan muss existieren
        $plan = DB::table('plan')->where('id', $planId)->first();
        if (!$plan) {
            return false;
        }

        // Parameter laden
        PlanParameter::load($planId);

        // IDs der Programme dynamisch aus DB
        $idChallenge = DB::table('m_first_program')->where('name', 'CHALLENGE')->value('id');
        $idExplore   = DB::table('m_first_program')->where('name', 'EXPLORE')->value('id');

        // --- Challenge prüfen ---
        if (pp("c_teams") > 0) {
            $ok = $this->checkSupportedPlan(
                $idChallenge,
                pp("c_teams"),
                pp("j_lanes"),
                pp("r_tables")
            );

            if (!$ok) {
                throw new \RuntimeException(
                    'Unsupported Challenge plan ' .
                    pp("c_teams") . '-' .
                    pp("j_lanes") . '-' .
                    pp("r_tables")
                );
            }
        }

        // --- Explore prüfen ---

        if (pp("e1_teams") > 0) {
            $ok = $this->checkSupportedPlan(
                $idExplore,
                pp("e1_teams"),
                pp("e1_lanes")
            );

            if (!$ok) {
                throw new \RuntimeException(
                    'Unsupported Explore plan ' .
                    pp("e1_teams") . '-' .
                    pp("e1_lanes")
                );
            }
        }

        if (pp("e2_teams") > 0) {
            $ok = $this->checkSupportedPlan(
                $idExplore,
                pp("e2_teams"),
                pp("e2_lanes")
            );

            if (!$ok) {
                throw new \RuntimeException(
                    'Unsupported Explore plan ' .
                    pp("e2_teams") . '-' .
                    pp("e2_lanes")
                );
            }
        }


        return true;
    }

    private function checkSupportedPlan(int $firstProgram, int $teams, int $lanes, ?int $tables = null): bool
    {
        $q = DB::table('m_supported_plan')
            ->where('first_program', $firstProgram)
            ->where('teams', $teams)
            ->where('lanes', $lanes);

        if (is_null($tables)) {
            $q->whereNull('tables');
        } else {
            $q->where('tables', $tables);
        }

        return $q->exists();
    }

    public function prepare(int $planId): void
    {
        // Alte Activities löschen
        DB::table('activity_group')->where('plan', $planId)->delete();

        // Generator-Lauf in s_generator eintragen
        DB::table('s_generator')->insert([
            'plan'  => $planId,
            'start' => Carbon::now(),
            'mode'  => 'job',
        ]);

        // Plan-Status aktualisieren
        DB::table('plan')->where('id', $planId)->update([
            'generator_status' => 'running',
        ]);
    }

    public function dispatchJob(int $planId, bool $withQualityEvaluation = false): void
    {
        GeneratePlanJob::dispatch($planId, $withQualityEvaluation);
    }

    public function run(int $planId, bool $withQualityEvaluation = false): void
    {
        try {
            require_once base_path("legacy/generator/generator_main.php");
            g_generator($planId);

            if ($withQualityEvaluation) {
                $evaluator = new QualityEvaluatorService();
                $evaluator->evaluatePlanId($planId);
            }

            $this->finalize($planId, 'done');
        } catch (\RuntimeException $e) {
            Log::error("Fehler beim Generieren des Plans {$planId}: " . $e->getMessage());
            $this->finalize($planId, 'failed');
        }
    }

    public function finalize(int $planId, string $status): void
    {
        DB::table('s_generator')
            ->where('plan', $planId)
            ->latest('id')
            ->limit(1)
            ->update(['end' => Carbon::now()]);

        DB::table('plan')
            ->where('id', $planId)
            ->update([
                'generator_status' => $status,
                'last_change'      => Carbon::now(),
            ]);
    }

    public function status(int $planId): string
    {
        return DB::table('plan')
            ->where('id', $planId)
            ->value('generator_status') ?? 'unknown';
    }
}