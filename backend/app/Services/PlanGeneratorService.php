<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Support\PlanParameter;
use App\Support\Helpers;
use App\Jobs\GeneratePlanJob;
use App\Enums\FirstProgram;
use App\Enums\GeneratorStatus;

class PlanGeneratorService
{
    public function isSupported(int $planId): array
    {
        // Parameter laden
        $params = PlanParameter::load($planId);

        // --- Finale validation ---
        // Finale events (level 3) require exactly 25 Challenge teams
        if ($params->get('g_finale')) {
            $cTeams = $params->get('c_teams');
            if ($cTeams != 25) {
                Log::warning('Finale event requires exactly 25 Challenge teams', [
                    'plan_id' => $planId,
                    'c_teams' => $cTeams,
                    'g_finale' => true,
                ]);
                return [
                    'supported' => false,
                    'error' => 'Finale-Events benötigen genau 25 Challenge-Teams',
                    'details' => "Aktuell konfiguriert: {$cTeams} Challenge-Teams. Bitte ändern Sie die Anzahl der Challenge-Teams auf 25."
                ];
            }
            // For finale with 25 teams, no need to check m_supported_plan
            // There is only one supported configuration
        } else {
            // --- Challenge prüfen (non-finale events) ---
            if ($params->get("c_teams") > 0) {
                $cTeams = $params->get("c_teams");
                $jLanes = $params->get("j_lanes");
                $rTables = $params->get("r_tables");
                
                $ok = $this->checkSupportedPlan(
                    FirstProgram::CHALLENGE->value,
                    $cTeams,
                    $jLanes,
                    $rTables
                );

                if (!$ok) {
                    Log::warning('Unsupported Challenge plan', [
                        'plan_id' => $planId,
                        'teams' => $cTeams,
                        'lanes' => $jLanes,
                        'tables' => $rTables,
                    ]);
                    return [
                        'supported' => false,
                        'error' => 'Challenge-Konfiguration wird nicht unterstützt',
                        'details' => "Die Kombination aus Challenge-Teams ({$cTeams}), Spuren ({$jLanes}) und Tischen ({$rTables}) wird nicht unterstützt. Bitte überprüfen Sie diese Parameter."
                    ];
                }
            }
        }

        // --- Explore prüfen ---

        if ($params->get("e1_teams") > 0) {
            $e1Teams = $params->get("e1_teams");
            $e1Lanes = $params->get("e1_lanes");
            
            $ok = $this->checkSupportedPlan(
                FirstProgram::EXPLORE->value,
                $e1Teams,
                $e1Lanes
            );

            if (!$ok) {
                Log::warning('Unsupported Explore plan', [
                    'plan_id' => $planId,
                    'teams' => $e1Teams,
                    'lanes' => $e1Lanes,
                ]);
                return [
                    'supported' => false,
                    'error' => 'Explore Vormittag-Konfiguration wird nicht unterstützt',
                    'details' => "Die Kombination aus Explore Vormittag-Teams ({$e1Teams}) und Spuren ({$e1Lanes}) wird nicht unterstützt. Bitte überprüfen Sie diese Parameter."
                ];
            }
        }

        if ($params->get("e2_teams") > 0) {
            $e2Teams = $params->get("e2_teams");
            $e2Lanes = $params->get("e2_lanes");
            
            $ok = $this->checkSupportedPlan(
                FirstProgram::EXPLORE->value,
                $e2Teams,
                $e2Lanes
            );

            if (!$ok) {
                Log::warning('Unsupported Explore plan', [
                    'plan_id' => $planId,
                    'teams' => $e2Teams,
                    'lanes' => $e2Lanes,
                ]);
                return [
                    'supported' => false,
                    'error' => 'Explore Nachmittag-Konfiguration wird nicht unterstützt',
                    'details' => "Die Kombination aus Explore Nachmittag-Teams ({$e2Teams}) und Spuren ({$e2Lanes}) wird nicht unterstützt. Bitte überprüfen Sie diese Parameter."
                ];
            }
        }


        return ['supported' => true];
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
            'generator_status' => GeneratorStatus::RUNNING->value,
        ]);
    }

    public function dispatchJob(int $planId, bool $withQualityEvaluation = false): void
    {
        GeneratePlanJob::dispatch($planId, $withQualityEvaluation);
    }

    public function run(int $planId, bool $withQualityEvaluation = false): void
    {
        try {
            \App\Core\PlanGeneratorCore::generate($planId);

            if ($withQualityEvaluation) {
                $evaluator = new QualityEvaluatorService();
                $evaluator->evaluatePlanId($planId);
            }

            $this->finalize($planId, GeneratorStatus::DONE);
        } catch (\Throwable $e) {
            Log::error('Fehler beim Generieren des Plans', [
                'plan_id' => $planId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->finalize($planId, GeneratorStatus::FAILED);
            // Re-throw to allow controller to handle error message formatting
            throw $e;
        }
    }

    public function finalize(int $planId, GeneratorStatus $status): void
    {
        DB::table('s_generator')
            ->where('plan', $planId)
            ->latest('id')
            ->limit(1)
            ->update(['end' => Carbon::now()]);

        DB::table('plan')
            ->where('id', $planId)
            ->update([
                'generator_status' => $status->value,
                'last_change'      => Carbon::now(),
            ]);
    }

    public function status(int $planId): GeneratorStatus
    {
        $value = DB::table('plan')
            ->where('id', $planId)
            ->value('generator_status');
        
        return GeneratorStatus::tryFrom($value) ?? GeneratorStatus::UNKNOWN;
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
        $params = \App\Support\PlanParameter::load($planId);
        (new \App\Core\FreeBlockGenerator($writer, $params))->insertFreeActivities();
}
}