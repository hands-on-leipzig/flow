<?php

namespace App\Jobs;

use App\Services\PlanGeneratorService;
use App\Services\EventAttentionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

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
        
        // Update attention status after successful plan generation
        $eventId = DB::table('plan')->where('id', $this->planId)->value('event');
        if ($eventId) {
            app(EventAttentionService::class)->updateEventAttentionStatus($eventId);
        }
    }
}