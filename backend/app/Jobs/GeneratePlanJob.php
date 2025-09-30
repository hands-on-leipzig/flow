<?php

namespace App\Jobs;

use App\Services\PlanGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GeneratePlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private int $planId;
    private bool $withQualityEvaluation;

    /**
     * Create a new job instance.
     */
    public function __construct(int $planId, bool $withQualityEvaluation = false)
    {
        $this->planId = $planId;
        $this->withQualityEvaluation = $withQualityEvaluation;
    }

    /**
     * Execute the job.
     */
    public function handle(PlanGeneratorService $generator): void
    {
        $generator->run($this->planId, $this->withQualityEvaluation);
    }
}