<?php

namespace App\Core;

use Illuminate\Support\Facades\Log;
use App\Support\PlanParameter;
use App\Models\Activity;
use App\Models\ExtraBlock;
use App\Models\MActivityTypeDetail;

class FreeBlockGenerator
{
    private ActivityWriter $writer;
    private int $planId;

    public function __construct(ActivityWriter $writer, PlanParameter $params)
    {
        $this->writer = $writer;
        $this->planId = $params->get('g_plan');

    }

    public function insertFreeActivities(): void
    {
        Log::info('FreeBlockGenerator: Starting free activities insertion');
        
        try {
            // Load ExtraBlocks with fixed times for this plan
            $blocks = ExtraBlock::where('plan', $this->planId)
            ->where('active', true)
            ->whereNotNull('start')
            ->get(['id', 'first_program', 'start', 'end']);

        foreach ($blocks as $block) {
            // Map first_program to activity type detail code
            $code = match ((int)$block->first_program) {
                3 => 'c_free_block', // CHALLENGE
                2 => 'e_free_block', // EXPLORE
                default => 'g_free_block', // joint
            };

            // Resolve activity_type_detail id
            $activityTypeDetailId = (int) (MActivityTypeDetail::where('code', $code)->value('id'));

            // Create group first
            $groupId = $this->writer->insertActivityGroup($code);

            // Insert activity with fixed start/end and extra_block reference
            Activity::create([
                'activity_group'        => $groupId,
                'activity_type_detail'  => $activityTypeDetailId,
                'start'                 => $block->start,
                'end'                   => $block->end,
                'extra_block'           => $block->id,
            ]);
        }

        } catch (\Throwable $e) {
            Log::error('FreeBlockGenerator: Error in free activities insertion', [
                'plan_id' => $this->planId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Failed to insert free activities: {$e->getMessage()}", 0, $e);
        }
    }
}


