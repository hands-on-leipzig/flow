<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\QRun;
use App\Models\QPlan;
use App\Models\QPlanTeam;
use App\Models\MParameter;
use App\Models\PlanParamValue;
use App\Models\Plan;
use App\Models\MSupportedPlan;
use App\Enums\FirstProgram;
use App\Enums\GeneratorStatus;
use App\Enums\QualityEvaluationStatus;
use App\Support\ChallengeShapedParamMap;
use App\Support\MatchPlanSpec;
use App\Support\PlanParameter;
use App\Support\ProgramPresence;
use Carbon\Carbon;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class QualityEvaluatorService
{

    public function generateQPlansFromSelection(int $runId): void
    {
        $qRun = DB::table('q_run')->where('id', $runId)->first();

        if (!$qRun) {
            throw new \Exception("q_run with ID $runId not found");
        }

        try {
            $selection = json_decode($qRun->selection, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \Exception("Invalid JSON in q_run.selection (ID $runId): " . $e->getMessage());
        }

        $firstProgramId = (int) ($selection['first_program'] ?? $qRun->first_program ?? FirstProgram::CHALLENGE->value);
        if (! ChallengeShapedParamMap::isSupported($firstProgramId)) {
            throw new \Exception("q_run $runId: first_program $firstProgramId is not Challenge-shaped (C or F8)");
        }

        $paramMap = ChallengeShapedParamMap::from($firstProgramId);

        QRun::where('id', $runId)->update([
            'first_program' => $firstProgramId,
        ]);

        Log::info('QualityEvaluatorService::generateQPlansFromSelection', [
            'q_run' => $runId,
            'first_program' => $firstProgramId,
            'selection' => $selection,
        ]);

        $parameters = MParameter::all()->keyBy('name');
        $eventId = $this->getOrCreateQualityEventId();

        $supportedPlans = MSupportedPlan::where('first_program', $firstProgramId)
            ->orderBy('teams')
            ->orderBy('lanes')
            ->orderBy('tables')
            ->get();

        foreach ($supportedPlans as $plan) {
            if (!$this->isPlanSupported($plan, $selection)) {
                continue;
            }

            $rounds = (int) ceil($plan->teams / $plan->lanes);
            $tables = (int) ($plan->tables ?? 0);

            // Robot check only exists for Challenge; F8 always off.
            $robotCheckOptions = $paramMap->supportsRobotCheck()
                ? ($selection['robot_check'] ?? ['off', 'on'])
                : ['off'];

            foreach ($robotCheckOptions as $rc) {
                $robotCheck = $rc === 'on' ? 1 : 0;
                $suffix = $paramMap->supportsRobotCheck()
                    ? ($robotCheck === 1 ? ' RC an' : ' RC aus')
                    : '';

                $newPlan = Plan::create([
                    'name' => "{$plan->teams}-{$plan->lanes}-{$tables} ({$rounds}){$suffix}",
                    'event' => $eventId,
                    'created' => Carbon::now(),
                    'last_change' => Carbon::now(),
                ]);

                $planId = $newPlan->id;
                $this->writeChallengeShapedPlanParams(
                    $planId,
                    $parameters,
                    $paramMap,
                    (int) $plan->teams,
                    (int) $plan->lanes,
                    $tables,
                    $robotCheck,
                );

                $transferDefault = (int) ($parameters[$paramMap->transfer()]->value ?? 0);

                QPlan::create([
                    'plan' => $planId,
                    'q_run' => $runId,
                    'first_program' => $firstProgramId,
                    'name' => $newPlan->name,
                    'c_teams' => $plan->teams,
                    'r_tables' => $tables,
                    'j_lanes' => $plan->lanes,
                    'j_rounds' => $rounds,
                    'r_asym' => ($tables === 4 && ($plan->teams % 4 === 1 || $plan->teams % 4 === 2)) ? 1 : 0,
                    'r_robot_check' => $robotCheck,
                    'r_duration_robot_check' => 0,
                    'c_duration_transfer' => $transferDefault,
                    'q1_ok_count' => null,
                    'q2_ok_count' => null,
                    'q3_ok_count' => null,
                    'q4_ok_count' => null,
                    'q5_idle_avg' => null,
                    'q5_idle_stddev' => null,
                ]);
            }
        }

        QRun::where('id', $runId)->update([
            'qplans_total' => QPlan::where('q_run', $runId)->count(),
        ]);
    }

    public function generateQPlansFromQPlans(int $newRunId, array $planIds)
    {
        $eventId = $this->getOrCreateQualityEventId();

        $programIds = QPlan::whereIn('id', $planIds)
            ->pluck('first_program')
            ->unique()
            ->values();

        if ($programIds->count() > 1) {
            throw new \Exception("ReRun q_run $newRunId: mixed first_program in selection is not supported");
        }

        $firstProgramId = (int) ($programIds->first() ?? FirstProgram::CHALLENGE->value);
        QRun::where('id', $newRunId)->update([
            'first_program' => $firstProgramId,
        ]);

        foreach ($planIds as $planId) {
            $original = QPlan::find($planId);

            if (!$original) {
                Log::warning("QPlan $planId nicht gefunden, wird übersprungen.");
                continue;
            }

            $originalPlan = Plan::find($original->plan);
            if (!$originalPlan) {
                Log::warning("Plan {$original->plan} nicht gefunden, QPlan $planId wird übersprungen.");
                continue;
            }

            $planCopy = $originalPlan->replicate();
            $planCopy->event = $eventId;
            $planCopy->save();

            $copy = $original->replicate();
            $copy->q_run = $newRunId;
            $copy->plan = $planCopy->id;
            $copy->first_program = $original->first_program ?? $firstProgramId;

            $copy->q1_ok_count = null;
            $copy->q2_ok_count = null;
            $copy->q3_ok_count = null;
            $copy->q4_ok_count = null;
            $copy->q5_idle_avg = null;
            $copy->q5_idle_stddev = null;
            $copy->q6_duration = null;
            $copy->calculated = 0;

            $copy->save();

            $paramValues = PlanParamValue::where('plan', $originalPlan->id)->get();

            foreach ($paramValues as $param) {
                $newParam = $param->replicate();
                $newParam->plan = $planCopy->id;
                $newParam->save();
            }
        }

        QRun::where('id', $newRunId)->update([
            'qplans_total' => QPlan::where('q_run', $newRunId)->count(),
        ]);
    }

    /**
     * Shared Q-event with Challenge and Future 8+ attached so either program
     * can be turned on per plan without purge removing its params.
     */
    private function getOrCreateQualityEventId(): int
    {
        $RP_NAME = '!!! QPlan RP - nur für den Qualitätstest verwendet !!!';
        $EVENT_NAME = '!!! QPlan Event - nur für den Qualitätstest verwendet !!!';

        $regionalPartner = DB::table('regional_partner')->where('name', $RP_NAME)->first();

        if (!$regionalPartner) {
            $regionalPartnerId = DB::table('regional_partner')->insertGetId([
                'name' => $RP_NAME,
                'region' => 0,
            ]);
        } else {
            $regionalPartnerId = $regionalPartner->id;
        }

        $event = DB::table('event')->where('name', $EVENT_NAME)->first();

        if (!$event) {
            $seasonId = DB::table('m_season')
                ->orderByDesc('year')
                ->value('id');

            $eventId = DB::table('event')->insertGetId([
                'name' => $EVENT_NAME,
                'regional_partner' => $regionalPartnerId,
                'level' => 1,
                'season' => $seasonId,
                'date' => Carbon::today(),
                'days' => 1,
            ]);
        } else {
            $eventId = $event->id;
        }

        $this->ensureQualityEventPrograms($eventId);

        return $eventId;
    }

    private function ensureQualityEventPrograms(int $eventId): void
    {
        foreach (ChallengeShapedParamMap::supportedIds() as $programId) {
            $exists = DB::table('event_program')
                ->where('event', $eventId)
                ->where('first_program', $programId)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('event_program')->insert([
                'event' => $eventId,
                'first_program' => $programId,
                'draht_id' => null,
                'contao_id' => null,
            ]);
        }
    }

    /**
     * Write mode/teams/lanes/tables for the selected program; keep the other
     * Challenge-shaped program attached but off (mode 0, teams 0).
     *
     * @param \Illuminate\Support\Collection<string, MParameter> $parameters
     */
    private function writeChallengeShapedPlanParams(
        int $planId,
        $parameters,
        ChallengeShapedParamMap $selected,
        int $teams,
        int $lanes,
        int $tables,
        int $robotCheck,
    ): void {
        $this->setPlanParam($parameters, $planId, $selected->mode(), 1);
        $this->setPlanParam($parameters, $planId, $selected->teams(), $teams);
        $this->setPlanParam($parameters, $planId, $selected->lanes(), $lanes);
        $this->setPlanParam($parameters, $planId, $selected->tables(), $tables);

        if ($selected->supportsRobotCheck() && $selected->robotCheck() !== null) {
            $this->setPlanParam($parameters, $planId, $selected->robotCheck(), $robotCheck);
        }

        foreach (ChallengeShapedParamMap::supportedIds() as $otherId) {
            if ($otherId === $selected->program->value) {
                continue;
            }
            $other = ChallengeShapedParamMap::from($otherId);
            $this->setPlanParam($parameters, $planId, $other->mode(), 0);
            $this->setPlanParam($parameters, $planId, $other->teams(), 0);
        }
    }

    /**
     * @param \Illuminate\Support\Collection<string, MParameter> $parameters
     */
    private function setPlanParam($parameters, int $planId, string $name, int|string $value): void
    {
        $param = $parameters->get($name);
        if (!$param) {
            throw new \RuntimeException("m_parameter '$name' not found");
        }

        PlanParamValue::create([
            'parameter' => $param->id,
            'plan' => $planId,
            'set_value' => $value,
        ]);
    }

    private function isPlanSupported(MSupportedPlan $plan, array $selection): bool
    {
        $teams = $plan->teams;
        $lanes = $plan->lanes;
        $tables = $plan->tables;

        $min = $selection['min_teams'] ?? 0;
        $max = $selection['max_teams'] ?? PHP_INT_MAX;
        $juryLanes = $selection['jury_lanes'] ?? [];
        $tableOptions = $selection['tables'] ?? [];
        $juryRounds = $selection['jury_rounds'] ?? [];

        $rounds = (int) ceil($teams / $lanes);

        return
            $teams >= $min &&
            $teams <= $max &&
            in_array($lanes, $juryLanes) &&
            in_array($tables, $tableOptions) &&
            in_array($rounds, $juryRounds);
    }
        


    /**
     * Main entry point to evaluate all quality metrics (Q1–Q6) for a given plan.
     */
    public function evaluate(int $qPlanId): void
    {
        $activities = $this->prepareEvaluationData($qPlanId);

        $this->calculateQ1($qPlanId, $activities);

        $pairing = $this->pairingQualityForQPlan($qPlanId);
        $this->persistQ2FromPairing($qPlanId, $pairing);
        $this->persistQ3FromPairing($qPlanId, $pairing);
        $this->persistQ4FromPairing($qPlanId, $pairing);

        $this->calculateQ5($qPlanId);
        $this->calculateQ6($qPlanId, $activities);

        // Set last_change on q_plan after calculation completes
        \App\Models\QPlan::where('id', $qPlanId)->update([
            'last_change' => now(),
        ]);

        // Log::info("qPlan {$qPlanId}: evaluation done");
    }


    // Evaluate quality for a given plan ID by looking up the corresponding QPlan entry.
    // This called from the GeneratePlan job after plan generation.
    public function evaluatePlanId(int $planId): void
    {
        $qPlan = \App\Models\QPlan::where('plan', $planId)->first();

        if (!$qPlan) {
            Log::warning("Kein QPlan für Plan ID $planId gefunden – Evaluation übersprungen");
            return;
        }

        $this->evaluate($qPlan->id);
    }

    /**
     * Whether a q_plan row is missing or older than the live plan.
     */
    public function isQPlanStale(?object $plan, ?object $qplan): bool
    {
        if ($qplan === null) {
            return true;
        }

        if (empty($plan?->last_change)) {
            return false;
        }

        $planChanged = Carbon::parse($plan->last_change);
        $qLast = $qplan->last_change ? Carbon::parse($qplan->last_change) : null;

        return $qLast === null || $qLast->lt($planChanged);
    }

    /**
     * Hard gates: plan cannot be meaningfully evaluated for Q1–Q6.
     *
     * @return array{not_evaluable: bool, reasons: list<string>}
     */
    public function assessHardGates(int $planId, int $firstProgram, ?object $plan = null): array
    {
        $reasons = [];
        $plan ??= DB::table('plan')->where('id', $planId)->first();

        if (! $plan) {
            return ['not_evaluable' => true, 'reasons' => ['Plan nicht gefunden']];
        }

        $genStatus = GeneratorStatus::tryFrom((string) ($plan->generator_status ?? ''))
            ?? GeneratorStatus::UNKNOWN;

        if ($genStatus === GeneratorStatus::FAILED) {
            $reasons[] = 'Generierung fehlgeschlagen';
        } elseif ($genStatus === GeneratorStatus::RUNNING) {
            $reasons[] = 'Generierung läuft';
        } elseif (! $genStatus->isDone()) {
            $reasons[] = 'Plan nicht generiert';
        }

        $groupCodes = $this->teamActivityGroupCodesForProgram($firstProgram);
        $requiredGroupIds = DB::table('m_activity_type_detail')
            ->whereIn('code', $groupCodes)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($requiredGroupIds !== []) {
            $presentCount = DB::table('activity_group')
                ->where('plan', $planId)
                ->whereIn('activity_type_detail', $requiredGroupIds)
                ->count();

            if ($presentCount < count($requiredGroupIds)) {
                $reasons[] = 'Robot-Game-Aktivitätsgruppen fehlen';
            }
        }

        if ($this->fetchTeamActivitiesForPlan($planId, $firstProgram)->isEmpty()) {
            $reasons[] = 'Keine Team-Aktivitäten im Plan';
        }

        $matchCountScoring = DB::table('match')
            ->where('plan', $planId)
            ->where('first_program', $firstProgram)
            ->where('round', '>=', 1)
            ->count();

        if ($matchCountScoring === 0) {
            $reasons[] = 'Kein Matchplan (Scoring-Runden)';
        }

        return [
            'not_evaluable' => $reasons !== [],
            'reasons' => $reasons,
        ];
    }

    /**
     * Soft checks after evaluate(): metrics may be partial.
     *
     * @return list<string>
     */
    public function assessSoftIncomplete(int $qPlanId): array
    {
        $reasons = [];
        $qplan = QPlan::findOrFail($qPlanId);
        $teams = (int) $qplan->c_teams;
        $planId = (int) $qplan->plan;
        $firstProgram = (int) $qplan->first_program;

        if ($teams > 0 && $qplan->q6_duration === null) {
            $reasons[] = 'Keine ermittelbare Gesamtdauer';
        }

        $teamsInMatches = DB::table('match')
            ->where('plan', $planId)
            ->where('first_program', $firstProgram)
            ->where('round', '>=', 1)
            ->get(['table_1_team', 'table_2_team']);

        $seenTeams = [];
        foreach ($teamsInMatches as $match) {
            foreach (['table_1_team', 'table_2_team'] as $col) {
                $t = (int) ($match->$col ?? 0);
                if ($t > 0) {
                    $seenTeams[$t] = true;
                }
            }
        }

        if (count($seenTeams) < $teams) {
            $reasons[] = 'Nicht alle Teams im Matchplan';
        }

        $noTransferData = QPlanTeam::where('q_plan', $qPlanId)
            ->where('q1_transition_1_2', 0)
            ->where('q1_transition_2_3', 0)
            ->where('q1_transition_3_4', 0)
            ->where('q1_transition_4_5', 0)
            ->count();

        if ($noTransferData > 0) {
            $reasons[] = 'Transferdaten unvollständig';
        }

        try {
            $pp = PlanParameter::load($planId);
            $spec = MatchPlanSpec::for(FirstProgram::from($firstProgram), $pp);
            $maxRound = (int) (DB::table('match')
                ->where('plan', $planId)
                ->where('first_program', $firstProgram)
                ->max('round') ?? -1);
            $roundSlots = $maxRound >= 0 ? $maxRound + 1 : 0;
            $expectedMatches = $spec->matchesPerRound * max(4, $roundSlots);
            $actualMatches = DB::table('match')
                ->where('plan', $planId)
                ->where('first_program', $firstProgram)
                ->count();

            if ($actualMatches < $expectedMatches) {
                $reasons[] = 'Matchplan unvollständig';
            }
        } catch (\Throwable) {
            // Derived match params missing — already covered by other checks.
        }

        return $reasons;
    }

    public function applyEvaluationStatus(int $qPlanId, QualityEvaluationStatus $status, array $reasons = []): void
    {
        QPlan::where('id', $qPlanId)->update([
            'evaluation_status' => $status->value,
            'evaluation_reasons' => $reasons === [] ? null : json_encode(array_values($reasons)),
            'last_change' => now(),
        ]);
    }

    /**
     * Ensure a QPlan exists and is up-to-date for a given plan + Challenge-shaped program.
     */
    public function ensureEvaluatedForPlan(int $planId, int $firstProgram, bool $force = false): QPlan
    {
        if (! ChallengeShapedParamMap::isSupported($firstProgram)) {
            throw new \InvalidArgumentException('first_program must be Challenge or Future 8+');
        }

        $plan = DB::table('plan')->where('id', $planId)->first();
        if (! $plan) {
            throw new \RuntimeException("Plan {$planId} not found");
        }

        $pp = PlanParameter::load($planId);
        $presence = ProgramPresence::forPlan($planId, $pp);
        $onPrograms = $presence->challengeShapedOnIds();

        if ($onPrograms !== [] && ! in_array($firstProgram, $onPrograms, true)) {
            throw new \InvalidArgumentException('first_program is not on for this plan');
        }

        $qplan = DB::table('q_plan')
            ->where('plan', $planId)
            ->where('first_program', $firstProgram)
            ->orderByDesc('id')
            ->first();

        if (! $force && ! $this->isQPlanStale($plan, $qplan)) {
            return QPlan::findOrFail($qplan->id);
        }

        $host = gethostname();
        $map = ChallengeShapedParamMap::from($firstProgram);

        $runId = DB::table('q_run')->insertGetId([
            'name' => "Auto für Plan {$planId}",
            'first_program' => $firstProgram,
            'comment' => 'Automatisch erstellt durch Preview',
            'selection' => null,
            'started_at' => Carbon::now(),
            'status' => 'running',
            'host' => $host,
        ]);

        $teams = (int) $pp->get($map->teams(), 0);
        $tables = (int) $pp->get($map->tables(), 0);
        $lanes = (int) $pp->get($map->lanes(), 0);
        $juryRounds = (int) ceil(max(1, $teams) / max(1, $lanes));
        $robotCheck = $map->supportsRobotCheck()
            ? (bool) $pp->get($map->robotCheck(), 0)
            : false;
        $rDurationRobotCheck = (int) $pp->get('r_duration_robot_check', 0);
        $transfer = (int) $pp->get($map->transfer(), 0);
        $rAsym = ($tables === 4 && ($teams % 4 === 1 || $teams % 4 === 2)) ? 1 : 0;

        $qPlanId = DB::table('q_plan')->insertGetId([
            'plan' => $planId,
            'q_run' => $runId,
            'first_program' => $firstProgram,
            'name' => $plan->name,
            'c_teams' => $teams,
            'r_tables' => $tables,
            'j_lanes' => $lanes,
            'j_rounds' => $juryRounds,
            'r_asym' => $rAsym,
            'r_robot_check' => $robotCheck,
            'r_duration_robot_check' => $rDurationRobotCheck,
            'c_duration_transfer' => $transfer,
            'calculated' => true,
            'evaluation_status' => QualityEvaluationStatus::OK->value,
            'evaluation_reasons' => null,
            'last_change' => null,
        ]);

        $hard = $this->assessHardGates($planId, $firstProgram, $plan);

        if ($hard['not_evaluable']) {
            $this->applyEvaluationStatus(
                $qPlanId,
                QualityEvaluationStatus::NOT_EVALUABLE,
                $hard['reasons'],
            );
        } else {
            $this->evaluate($qPlanId);
            $softReasons = $this->assessSoftIncomplete($qPlanId);
            $this->applyEvaluationStatus(
                $qPlanId,
                $softReasons === [] ? QualityEvaluationStatus::OK : QualityEvaluationStatus::INCOMPLETE,
                $softReasons,
            );
        }

        $totals = DB::table('q_plan')->where('q_run', $runId)->count();
        DB::table('q_run')->where('id', $runId)->update([
            'qplans_total' => $totals,
            'qplans_calculated' => $totals,
            'finished_at' => Carbon::now(),
            'status' => 'done',
        ]);

        DB::table('q_plan')
            ->where('plan', $planId)
            ->where('first_program', $firstProgram)
            ->where('id', '!=', $qPlanId)
            ->delete();

        return QPlan::findOrFail($qPlanId);
    }

    /**
     * Load all relevant activities for a given plan, including joins to group and type info.
     */
    private function prepareEvaluationData(int $qPlanId): Collection
    {
        $planId = $this->planIdForQPlan($qPlanId);
        $firstProgram = (int) ($this->qPlanRow($qPlanId)->first_program ?? FirstProgram::CHALLENGE->value);
        $activities = $this->fetchTeamActivitiesForPlan($planId, $firstProgram);

        DB::table('q_plan_team')->where('q_plan', $qPlanId)->delete();

        $teamCount = $this->teamsForQPlan($qPlanId);

        for ($team = 1; $team <= $teamCount; $team++) {
            DB::table('q_plan_team')->insert([
                'q_plan' => $qPlanId,
                'team' => $team,

                'q1_ok' => 0,
                'q1_transition_1_2' => 0,
                'q1_transition_2_3' => 0,
                'q1_transition_3_4' => 0,
                'q1_transition_4_5' => 0,

                'q2_ok' => 0,
                'q2_tables' => 0,

                'q3_ok' => 0,
                'q3_teams' => 0,

                'q4_ok' => 0,

                'q5_idle_0_1' => 0,
                'q5_idle_1_2' => 0,
                'q5_idle_2_3' => 0,
                'q5_idle_avg' => 0,
            ]);
        }

        return $activities;
    }

    /** @return list<string> */
    private function teamActivityCodesForProgram(int $firstProgram): array
    {
        if ($firstProgram === FirstProgram::FUTURE_8->value) {
            return ['f8_j_with_team', 'f8_r_match'];
        }

        return ['j_with_team', 'r_match', 'r_check'];
    }

    /** Judging package + RG rounds 0–3 (exclude finals), per program. */
    /** @return list<string> */
    private function teamActivityGroupCodesForProgram(int $firstProgram): array
    {
        if ($firstProgram === FirstProgram::FUTURE_8->value) {
            return [
                'f8_j_package',
                'f8_test_round',
                'f8_round_1',
                'f8_round_2',
                'f8_round_3',
            ];
        }

        return [
            'j_package',
            'r_test_round',
            'r_round_1',
            'r_round_2',
            'r_round_3',
        ];
    }

    private function fetchTeamActivitiesForPlan(int $planId, int $firstProgram): Collection
    {
        $activityCodes = $this->teamActivityCodesForProgram($firstProgram);
        $groupCodes = $this->teamActivityGroupCodesForProgram($firstProgram);

        $activityIds = DB::table('m_activity_type_detail')
            ->whereIn('code', $activityCodes)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $groupIds = DB::table('m_activity_type_detail')
            ->whereIn('code', $groupCodes)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($activityIds === [] || $groupIds === []) {
            return collect();
        }

        return Activity::query()
            ->select([
                'activity.start',
                'activity.end',
                'activity.jury_lane',
                'activity.jury_team',
                'activity.table_1',
                'activity.table_1_team',
                'activity.table_2',
                'activity.table_2_team',
                'activity.activity_type_detail as activity_atd',
                'activity_group.activity_type_detail as activity_group_atd',
            ])
            ->join('activity_group', 'activity.activity_group', '=', 'activity_group.id')
            ->where('activity_group.plan', $planId)
            ->whereIn('activity_group.activity_type_detail', $groupIds)
            ->whereIn('activity.activity_type_detail', $activityIds)
            ->orderBy('activity.start')
            ->get();
    }

    /** @return list<string> */
    private function teamActivityCodesForQPlan(int $qPlanId): array
    {
        $firstProgram = (int) ($this->qPlanRow($qPlanId)->first_program ?? FirstProgram::CHALLENGE->value);

        return $this->teamActivityCodesForProgram($firstProgram);
    }

    /** Judging package + RG rounds 0–3 (exclude finals), per program. */
    /** @return list<string> */
    private function teamActivityGroupCodesForQPlan(int $qPlanId): array
    {
        $firstProgram = (int) ($this->qPlanRow($qPlanId)->first_program ?? FirstProgram::CHALLENGE->value);

        return $this->teamActivityGroupCodesForProgram($firstProgram);
    }

    private function qPlanRow(int $qPlanId): object
    {
        $row = DB::table('q_plan')->where('id', $qPlanId)->first();
        if (!$row) {
            throw new \RuntimeException("q_plan $qPlanId not found");
        }

        return $row;
    }

    private function planIdForQPlan(int $qPlanId): int
    {
        return (int) $this->qPlanRow($qPlanId)->plan;
    }

    private function paramMapForQPlan(int $qPlanId): ChallengeShapedParamMap
    {
        $firstProgram = (int) ($this->qPlanRow($qPlanId)->first_program ?? FirstProgram::CHALLENGE->value);

        return ChallengeShapedParamMap::from($firstProgram);
    }

    private function teamsForQPlan(int $qPlanId): int
    {
        $map = $this->paramMapForQPlan($qPlanId);
        $fromParams = $this->getParameterValueForPlan($qPlanId, $map->teams());
        if ($fromParams > 0) {
            return $fromParams;
        }

        // q_plan.c_teams stores the grid team count for either program.
        return (int) ($this->qPlanRow($qPlanId)->c_teams ?? 0);
    }

    private function tablesForQPlan(int $qPlanId): int
    {
        $map = $this->paramMapForQPlan($qPlanId);
        $fromParams = $this->getParameterValueForPlan($qPlanId, $map->tables());
        if ($fromParams > 0) {
            return $fromParams;
        }

        return (int) ($this->qPlanRow($qPlanId)->r_tables ?? 0);
    }

    private function transferForQPlan(int $qPlanId): int
    {
        $map = $this->paramMapForQPlan($qPlanId);

        return $this->getParameterValueForPlan($qPlanId, $map->transfer());
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function matchesForQPlan(int $qPlanId, array $rounds)
    {
        $row = $this->qPlanRow($qPlanId);

        return DB::table('match')
            ->where('plan', $row->plan)
            ->where('first_program', $row->first_program)
            ->whereIn('round', $rounds)
            ->orderBy('round')
            ->orderBy('match_no')
            ->get();
    }

    private function getParameterValueForPlan(int $qPlanId, string $name): int
    {
        $value = DB::table('q_plan')
            ->join('plan', 'q_plan.plan', '=', 'plan.id')
            ->join('plan_param_value', 'plan_param_value.plan', '=', 'plan.id')
            ->join('m_parameter', 'plan_param_value.parameter', '=', 'm_parameter.id')
            ->where('q_plan.id', $qPlanId)
            ->where('m_parameter.name', $name)
            ->value('plan_param_value.set_value');

        if ($value !== null) {
            return (int) $value;
        }

        $default = DB::table('m_parameter')
            ->where('name', $name)
            ->value('value');

        return (int) $default;
    }


    /**
     * Evaluate Q1: Check for minimum gap between the 5 relevant activities.
     */
    private function calculateQ1(int $qPlanId, Collection $activities): void
    {
        $minGap = $this->transferForQPlan($qPlanId);
        $teamCount = $this->teamsForQPlan($qPlanId);
        $map = $this->paramMapForQPlan($qPlanId);

        $jWithTeamCode = $map->program === FirstProgram::FUTURE_8 ? 'f8_j_with_team' : 'j_with_team';
        $rMatchCode = $map->program === FirstProgram::FUTURE_8 ? 'f8_r_match' : 'r_match';
        $rCheckCode = $map->program === FirstProgram::FUTURE_8 ? null : 'r_check';

        $jWithTeamId = \App\Models\MActivityTypeDetail::where('code', $jWithTeamCode)->value('id');
        $rMatchId = \App\Models\MActivityTypeDetail::where('code', $rMatchCode)->value('id');
        $rCheckId = $rCheckCode
            ? \App\Models\MActivityTypeDetail::where('code', $rCheckCode)->value('id')
            : null;

        for ($team = 1; $team <= $teamCount; $team++) {
            $teamActivities = $activities->filter(function ($a) use ($team, $jWithTeamId, $rMatchId, $rCheckId) {
                if ($a->activity_atd === $jWithTeamId) {
                    return $a->jury_team === $team;
                }
                if ($a->activity_atd === $rMatchId || ($rCheckId !== null && $a->activity_atd === $rCheckId)) {
                    return $a->table_1_team === $team || $a->table_2_team === $team;
                }

                return false;
            })->sortBy('start')->values();

            // Merge consecutive Robot Check + Robot Match pairs into single activities
            $mergedActivities = [];
            $i = 0;
            while ($i < $teamActivities->count()) {
                $current = $teamActivities[$i];

                if ($rCheckId !== null &&
                    $current->activity_atd === $rCheckId &&
                    $i + 1 < $teamActivities->count() &&
                    $teamActivities[$i + 1]->activity_atd === $rMatchId) {

                    $merged = (object) [
                        'start' => $current->start,
                        'end' => $teamActivities[$i + 1]->end,
                    ];
                    $mergedActivities[] = $merged;
                    $i += 2;
                } else {
                    $mergedActivities[] = $current;
                    $i++;
                }
            }

            // Calculate all 4 gaps and check if all are >= minGap
            $allTransitions = [];
            $allGapsOk = true;

            for ($i = 1; $i < count($mergedActivities); $i++) {
                $prev = new \DateTime($mergedActivities[$i - 1]->end);
                $curr = new \DateTime($mergedActivities[$i]->start);
                $gap = ($curr->getTimestamp() - $prev->getTimestamp()) / 60; // gap in minutes

                $allTransitions[$i] = $gap;

                // error_log("T{$team} | {$prev->format('H:i')} → {$curr->format('H:i')} | Δ {$gap} min");

                if ($gap < $minGap) {
                    $allGapsOk = false;
                }
            }

            // Store result in q_plan_team
            QPlanTeam::where('q_plan', $qPlanId)
                ->where('team', $team)
                ->update([
                    'q1_ok' => $allGapsOk,
                    'q1_transition_1_2' => $allTransitions[1] ?? 0,
                    'q1_transition_2_3' => $allTransitions[2] ?? 0,
                    'q1_transition_3_4' => $allTransitions[3] ?? 0,
                    'q1_transition_4_5' => $allTransitions[4] ?? 0,
                ]);
        }

        // Count number of teams that passed Q1
        $ok_count = QPlanTeam::where('q_plan', $qPlanId)
            ->where('q1_ok', true)
            ->count();

        DB::table('q_plan')
            ->where('id', $qPlanId)
            ->update(['q1_ok_count' => $ok_count]);

    }

    /**
     * Single pairing calculator for Q2/Q3/Q4 — MatchPlanPairingQuality only.
     *
     * @return array{
     *   scoring_rounds: list<int>,
     *   match_summary: list<array<string, mixed>>,
     *   q2_ok_count: int,
     *   q3_ok_count: int,
     *   q4_ok_count: int,
     *   teams: int,
     *   tables: int
     * }
     */
    private function pairingQualityForQPlan(int $qPlanId): array
    {
        $row = $this->qPlanRow($qPlanId);
        $teams = $this->teamsForQPlan($qPlanId);
        $tables = $this->tablesForQPlan($qPlanId);

        $matches = DB::table('match')
            ->where('plan', $row->plan)
            ->where('first_program', $row->first_program)
            ->orderBy('round')
            ->orderBy('match_no')
            ->get()
            ->map(static fn ($m) => [
                'round' => (int) $m->round,
                'match_no' => (int) $m->match_no,
                'table_1' => (int) $m->table_1,
                'table_2' => (int) $m->table_2,
                'table_1_team' => (int) $m->table_1_team,
                'table_2_team' => (int) $m->table_2_team,
            ])
            ->all();

        return app(MatchPlanPairingQuality::class)->evaluate($matches, $teams, $tables);
    }

    /**
     * @param  array{match_summary: list<array<string, mixed>>, tables: int}  $pairing
     */
    private function persistQ2FromPairing(int $qPlanId, array $pairing): void
    {
        $distribution = [1 => 0, 2 => 0, 3 => 0];
        $totalScore = 0;
        $teamsProcessed = 0;
        $tablesAvailable = (int) $pairing['tables'];

        foreach ($pairing['match_summary'] as $entry) {
            $team = (int) $entry['team'];
            $distinctTables = (int) ($entry['tables'] ?? 0);
            $targetTables = max(1, (int) ($entry['q2_target'] ?? ($tablesAvailable === 2 ? 2 : 3)));

            QPlanTeam::where('q_plan', $qPlanId)
                ->where('team', $team)
                ->update([
                    'q2_ok' => (bool) ($entry['q2_ok'] ?? false),
                    'q2_tables' => $distinctTables,
                ]);

            if ($distinctTables >= 3) {
                $distribution[3]++;
            } elseif ($distinctTables >= 1) {
                $distribution[$distinctTables]++;
            }

            $totalScore += min(100, ($distinctTables / $targetTables) * 100);
            $teamsProcessed++;
        }

        $avgScore = $teamsProcessed > 0 ? $totalScore / $teamsProcessed : 0;

        DB::table('q_plan')
            ->where('id', $qPlanId)
            ->update([
                'q2_ok_count' => (int) ($pairing['q2_ok_count'] ?? 0),
                'q2_1_count' => $distribution[1],
                'q2_2_count' => $distribution[2],
                'q2_3_count' => $distribution[3],
                'q2_score_avg' => round($avgScore, 2),
            ]);
    }

    /**
     * @param  array{match_summary: list<array<string, mixed>>, scoring_rounds: list<int>}  $pairing
     */
    private function persistQ3FromPairing(int $qPlanId, array $pairing): void
    {
        $distribution = [1 => 0, 2 => 0, 3 => 0];
        $totalScore = 0;
        $teamsProcessed = 0;
        $targetOpponents = max(1, count($pairing['scoring_rounds'] ?? []));

        foreach ($pairing['match_summary'] as $entry) {
            $team = (int) $entry['team'];
            $uniqueOpponents = (int) ($entry['teams'] ?? 0);
            $q3Ok = (bool) ($entry['q3_ok'] ?? false);

            QPlanTeam::where('q_plan', $qPlanId)
                ->where('team', $team)
                ->update([
                    'q3_ok' => $q3Ok,
                    'q3_teams' => $uniqueOpponents,
                ]);

            if ($q3Ok) {
                $distribution[3]++;
            } elseif ($uniqueOpponents === 1) {
                $distribution[1]++;
            } elseif ($uniqueOpponents >= 2) {
                $distribution[2]++;
            }

            $totalScore += min(100, ($uniqueOpponents / $targetOpponents) * 100);
            $teamsProcessed++;
        }

        $avgScore = $teamsProcessed > 0 ? $totalScore / $teamsProcessed : 0;

        DB::table('q_plan')
            ->where('id', $qPlanId)
            ->update([
                'q3_ok_count' => (int) ($pairing['q3_ok_count'] ?? 0),
                'q3_1_count' => $distribution[1],
                'q3_2_count' => $distribution[2],
                'q3_3_count' => $distribution[3],
                'q3_score_avg' => round($avgScore, 2),
            ]);
    }

    /**
     * @param  array{match_summary: list<array<string, mixed>>, q4_ok_count: int}  $pairing
     */
    private function persistQ4FromPairing(int $qPlanId, array $pairing): void
    {
        foreach ($pairing['match_summary'] as $entry) {
            QPlanTeam::where('q_plan', $qPlanId)
                ->where('team', (int) $entry['team'])
                ->update([
                    'q4_ok' => (bool) ($entry['q4_ok'] ?? false),
                ]);
        }

        DB::table('q_plan')
            ->where('id', $qPlanId)
            ->update([
                'q4_ok_count' => (int) ($pairing['q4_ok_count'] ?? 0),
            ]);
    }

    /**
     * Evaluate Q5: Count idle matches between rounds.
     */
    private function calculateQ5(int $qPlanId): void
    {
        $matches = $this->matchesForQPlan($qPlanId, [0, 1, 2, 3]);
        $teams = QPlanTeam::where('q_plan', $qPlanId)->pluck('team');
        $teamIdleCounts = [];

        foreach ($teams as $team) {
            $idle = 0;
            $round = -1;

            $idleCounts = [
                'q5_idle_0_1' => 0,
                'q5_idle_1_2' => 0,
                'q5_idle_2_3' => 0,
            ];

            foreach ($matches as $match) {
                $isPlaying = ($match->table_1_team === $team || $match->table_2_team === $team);

                if ($isPlaying) {
                    $round++;

                    if ($round === 1) {
                        $idleCounts['q5_idle_0_1'] = $idle;
                    } elseif ($round === 2) {
                        $idleCounts['q5_idle_1_2'] = $idle;
                    } elseif ($round === 3) {
                        $idleCounts['q5_idle_2_3'] = $idle;
                    }

                    $idle = 0;
                } else {
                    $idle++;
                }
            }

            $avgIdle = ($idleCounts['q5_idle_0_1'] + $idleCounts['q5_idle_1_2'] + $idleCounts['q5_idle_2_3']) / 3;
            $teamIdleCounts[] = $avgIdle;

            QPlanTeam::where('q_plan', $qPlanId)
                ->where('team', $team)
                ->update([
                    'q5_idle_0_1' => $idleCounts['q5_idle_0_1'],
                    'q5_idle_1_2' => $idleCounts['q5_idle_1_2'],
                    'q5_idle_2_3' => $idleCounts['q5_idle_2_3'],
                    'q5_idle_avg' => $avgIdle,
                ]);
        }

        $values = array_values($teamIdleCounts);
        if ($values === []) {
            QPlan::where('id', $qPlanId)->update([
                'q5_idle_avg' => 0,
                'q5_idle_stddev' => 0,
            ]);

            return;
        }

        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn ($v) => pow($v - $mean, 2), $values)) / count($values);
        $stdDev = sqrt($variance);

        QPlan::where('id', $qPlanId)
            ->update([
                'q5_idle_avg' => $mean,
                'q5_idle_stddev' => $stdDev,
            ]);
    }

    /**
     * Calculate Q6: Overall event duration from first to last activity across all teams.
     */
    private function calculateQ6(int $qPlanId, Collection $activities): void
    {
        if ($activities->isEmpty()) {
            return;
        }

        // Find the earliest start time across all activities
        $firstStart = $activities->min('start');
        
        // Find the latest end time across all activities
        $lastEnd = $activities->max('end');

        if (!$firstStart || !$lastEnd) {
            return;
        }

        // Calculate duration in minutes
        $startTime = new \DateTime($firstStart);
        $endTime = new \DateTime($lastEnd);
        $durationMinutes = ($endTime->getTimestamp() - $startTime->getTimestamp()) / 60;

        // Update q_plan with Q6 duration
        QPlan::where('id', $qPlanId)
            ->update([
                'q6_duration' => round($durationMinutes),
            ]);
    }

}