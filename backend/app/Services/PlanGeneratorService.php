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
        // Parameter laden
        $params = PlanParameter::load($planId);

        // IDs der Programme dynamisch aus DB
        $idChallenge = DB::table('m_first_program')->where('name', 'CHALLENGE')->value('id');
        $idExplore   = DB::table('m_first_program')->where('name', 'EXPLORE')->value('id');

        // --- Challenge prüfen ---
        if ($params->get("c_teams") > 0) {
            $ok = $this->checkSupportedPlan(
                $idChallenge,
                $params->get("c_teams"),
                $params->get("j_lanes"),
                $params->get("r_tables")
            );

            if (!$ok) {
                Log::warning('Unsupported Challenge plan', [
                    'plan_id' => $planId,
                    'teams' => $params->get('c_teams'),
                    'lanes' => $params->get('j_lanes'),
                    'tables' => $params->get('r_tables'),
                ]);
                return false;
            }
        }

        // --- Explore prüfen ---

        if ($params->get("e1_teams") > 0) {
            $ok = $this->checkSupportedPlan(
                $idExplore,
                $params->get("e1_teams"),
                $params->get("e1_lanes")
            );

            if (!$ok) {
                Log::warning('Unsupported Explore plan', [
                    'plan_id' => $planId,
                    'teams' => $params->get('e1_teams'),
                    'lanes' => $params->get('e1_lanes'),
                ]);
                return false;
            }
        }

        if ($params->get("e2_teams") > 0) {
            $ok = $this->checkSupportedPlan(
                $idExplore,
                $params->get("e2_teams"),
                $params->get("e2_lanes")
            );

            if (!$ok) {
                Log::warning('Unsupported Explore plan', [
                    'plan_id' => $planId,
                    'teams' => $params->get('e2_teams'),
                    'lanes' => $params->get('e2_lanes'),
                ]);
                return false;
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
        } catch (\Throwable $e) {
            Log::error('Fehler beim Generieren des Plans', [
                'plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            $this->finalize($planId, 'failed');
        }
    }

    public function runFUTURE(int $planId, bool $withQualityEvaluation = false): void
    {
        try {
            $core = new \App\Core\PlanGeneratorCore($planId);
            $core->generate();

            if ($withQualityEvaluation) {
                $evaluator = new QualityEvaluatorService();
                $evaluator->evaluatePlanId($planId);
            }

            $this->finalize($planId, 'done');
        } catch (\Throwable $e) {
            Log::error('Fehler beim Generieren des Plans', [
                'plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
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

    public function generateLite(int $planId): void
    {
        // Schritt 1: Alle activity_groups finden mit passenden activity_type_detail-Codes
        $groupIds = DB::table('activity_group AS ag')
            ->join('m_activity_type_detail AS atd', 'ag.activity_type_detail', '=', 'atd.id')
            ->where('ag.plan', $planId)
            ->whereIn('atd.code', ['c_free_block', 'e_free_block', 'g_free_block'])
            ->pluck('ag.id');

        // Schritt 2: Löschen – Activities hängen per FK dran und gehen mit weg
        if ($groupIds->isNotEmpty()) {
            DB::table('activity_group')
                ->whereIn('id', $groupIds)
                ->delete();
        }

        // Schritt 3: Neue FreeActivities einsetzen
        $writer = new \App\Core\ActivityWriter($planId);
        $writer->insertFreeActivities();
}
}