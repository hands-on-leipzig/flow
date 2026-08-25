<?php

namespace App\Jobs;

use App\Models\QPlan;
use App\Models\QRun;
use App\Support\ChallengeShapedParamMap;
use App\Support\PlanParameter;
use App\Support\ProgramPresence;
use App\Services\PlanGeneratorService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExecuteQPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $runId;

    public $timeout = 300;
    public $tries = 1;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle(): void
    {
        QRun::where('id', $this->runId)->update([
            'status' => 'running',
        ]);

        $qPlan = QPlan::where('q_run', $this->runId)
            ->where('calculated', false)
            ->first();

        if (!$qPlan) {
            QRun::where('id', $this->runId)->update([
                'finished_at' => Carbon::now(),
                'status' => 'done',
            ]);

            Log::info("ExecuteQPlanJob: qRun {$this->runId} completed");
            return;
        }

        $planId = (int) $qPlan->plan;
        Log::info("ExecuteQPlanJob: Processing qPlan {$qPlan->id}", [
            'q_run' => $this->runId,
            'plan_id' => $planId,
            'first_program' => $qPlan->first_program,
            'c_teams' => $qPlan->c_teams,
            'j_lanes' => $qPlan->j_lanes,
            'r_tables' => $qPlan->r_tables,
        ]);

        $generator = new PlanGeneratorService();

        try {
            $this->assertProgramReady($qPlan);

            $support = $generator->isSupported($planId);
            if (! ($support['supported'] ?? false)) {
                Log::warning('ExecuteQPlanJob: plan not supported, skipping generate', [
                    'q_plan' => $qPlan->id,
                    'plan_id' => $planId,
                    'error' => $support['error'] ?? null,
                    'details' => $support['details'] ?? null,
                ]);
            } else {
                $generator->prepare($planId, 'job', null);
                $generator->run($planId, true);

                $activityGroups = DB::table('activity_group')->where('plan', $planId)->count();
                if ($activityGroups < 1) {
                    throw new \RuntimeException(
                        "Generate finished with 0 activity_group rows for plan {$planId} (likely mode off / stale worker)."
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::error('ExecuteQPlanJob: generate/evaluate failed', [
                'q_plan' => $qPlan->id,
                'plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
        }

        QPlan::where('id', $qPlan->id)->update(['calculated' => true]);
        QRun::where('id', $this->runId)->increment('qplans_calculated');

        ExecuteQPlanJob::dispatch($this->runId);
    }

    /**
     * Quality plans must have the selected Challenge-shaped program on
     * (attached + mode=1 + teams>0) before we call the generator.
     */
    private function assertProgramReady(QPlan $qPlan): void
    {
        $planId = (int) $qPlan->plan;
        $firstProgram = (int) ($qPlan->first_program ?? 0);
        if (! ChallengeShapedParamMap::isSupported($firstProgram)) {
            throw new \RuntimeException("q_plan {$qPlan->id}: first_program {$firstProgram} is not C/F8");
        }

        $map = ChallengeShapedParamMap::from($firstProgram);
        $params = PlanParameter::load($planId);
        $presence = ProgramPresence::forPlan($planId, $params);

        if (! $presence->challengeShapedOn($firstProgram)) {
            throw new \RuntimeException(
                "q_plan {$qPlan->id}: program {$firstProgram} is not on "
                ."(need event_program + {$map->mode()}=1 + {$map->teams()}>0). "
                .'Restart queue:work if create still writes plans without mode.'
            );
        }
    }
}
