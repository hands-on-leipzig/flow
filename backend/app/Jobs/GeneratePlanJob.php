<?php

namespace App\Jobs;

use App\Services\PlanGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GeneratePlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private int $planId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $planId)
    {
        $this->planId = $planId;
    }

    /**
     * Execute the job.
     */
    public function handle(PlanGenerator $generator): void
    {
        $generator->run($this->planId);
    }
}