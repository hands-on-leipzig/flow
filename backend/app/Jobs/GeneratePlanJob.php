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
    private ?int $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $planId, bool $withQualityEvaluation = false, ?int $userId = null)
    {
        $this->planId = $planId;
        $this->withQualityEvaluation = $withQualityEvaluation;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(PlanGeneratorService $generator): void
    {
        $generator->run($this->planId, $this->withQualityEvaluation);
    }
}