<?php

namespace App\Core;

use Illuminate\Support\Facades\Log;
use App\Support\PlanParameter;
use App\Models\Activity;
use App\Models\ExtraBlock;
use App\Models\MActivityTypeDetail;
use App\Enums\FirstProgram;

class FreeBlockGenerator
{
    private ActivityWriter $writer;
    private int $planId;
    private PlanParameter $params;

    public function __construct(ActivityWriter $writer, PlanParameter $params)
    {
        $this->writer = $writer;
        $this->params = $params;
        $this->planId = $params->get('g_plan');

    }

    public function insertFreeActivities(): void
    {
        // Get plan configuration to check which programs are enabled
        $eMode = $this->params->get('e_mode');
        $cMode = $this->params->get('c_mode');
        
        Log::info('FreeBlockGenerator::insertFreeActivities', [
            'plan_id' => $this->planId,
            'e_mode' => $eMode,
            'c_mode' => $cMode,
        ]);
        
        try {
            
            // Load ExtraBlocks with fixed times for this plan
            $blocks = ExtraBlock::where('plan', $this->planId)
            ->where('active', true)
            ->whereNotNull('start')
            ->get(['id', 'first_program', 'start', 'end']);

        foreach ($blocks as $block) {
            $blockProgram = (int)$block->first_program;
            
            // Skip Explore blocks if Explore is disabled (e_mode = 0)
            if ($blockProgram === FirstProgram::EXPLORE->value && $eMode == 0) {
                // Log::info("FreeBlockGenerator: Skipping Explore block {$block->id} (Explore disabled in plan)");
                continue;
            }
            
            // Skip Challenge blocks if Challenge is disabled (c_mode = 0)
            if ($blockProgram === FirstProgram::CHALLENGE->value && $cMode == 0) {
                // Log::info("FreeBlockGenerator: Skipping Challenge block {$block->id} (Challenge disabled in plan)");
                continue;
            }
            
            // Map first_program to activity type detail code
            $code = match ($blockProgram) {
                FirstProgram::CHALLENGE->value => 'c_free_block',
                FirstProgram::EXPLORE->value => 'e_free_block',
                FirstProgram::JOINT->value => 'g_free_block',
                default => 'g_free_block', // Fallback for unknown
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
            
            // Provide more specific error messages based on exception type
            $message = "Fehler beim Einfügen der freien Aktivitäten";
            if (str_contains($e->getMessage(), "Parameter '")) {
                $message = "Ungültiger Parameterwert in freien Blöcken";
            } elseif (str_contains($e->getMessage(), "not found") || str_contains($e->getMessage(), "existiert nicht")) {
                $message = "Fehlende Daten für freie Blöcke";
            } elseif (str_contains($e->getMessage(), "activity_type_detail")) {
                $message = "Fehler bei der Aktivitätstyp-Zuordnung";
            } elseif ($e instanceof \RuntimeException) {
                $message = $e->getMessage();
            } else {
                $message .= ": {$e->getMessage()}";
            }
            
            throw new \RuntimeException($message, 0, $e);
        }
    }
}


