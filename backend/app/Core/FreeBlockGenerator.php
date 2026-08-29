<?php

namespace App\Core;

use App\Enums\FirstProgram;
use App\Models\Activity;
use App\Models\ExtraBlock;
use App\Models\MActivityTypeDetail;
use App\Support\ExtraBlockActivityTypeCode;
use App\Support\PlanParameter;
use App\Support\ProgramPresence;
use Illuminate\Support\Facades\Log;

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
        $presence = ProgramPresence::forPlan($this->planId, $this->params);

        Log::info('FreeBlockGenerator::insertFreeActivities', [
            'plan_id' => $this->planId,
            'explore_on' => $presence->exploreOn(),
            'lead_program_id' => $presence->leadProgramId(),
        ]);

        try {

            // Load ExtraBlocks with fixed times for this plan (type = free only)
            $blocks = ExtraBlock::where('plan', $this->planId)
                ->where('active', true)
                ->where('type', 'free')
                ->whereNotNull('start')
                ->get(['id', 'first_program', 'start', 'end', 'public_time']);

            foreach ($blocks as $block) {
                $blockProgram = (int) ($block->first_program ?? FirstProgram::JOINT->value);

                if ($blockProgram !== FirstProgram::JOINT->value && ! $presence->programOn($blockProgram)) {
                    continue;
                }

                if ($blockProgram === FirstProgram::JOINT->value) {
                    $anyOn = false;
                    foreach ($presence->attachedIds() as $programId) {
                        if ($presence->programOn($programId)) {
                            $anyOn = true;
                            break;
                        }
                    }
                    if (! $anyOn) {
                        continue;
                    }
                }

                $code = ExtraBlockActivityTypeCode::forFree($blockProgram);

                // Resolve activity_type_detail id
                $activityTypeDetailId = (int) (MActivityTypeDetail::where('code', $code)->value('id'));
                $atdPublicTime = (bool) MActivityTypeDetail::where('id', $activityTypeDetailId)->value('public_time');

                // Create group first
                $groupId = $this->writer->insertActivityGroup($code);

                // Insert activity with fixed start/end and extra_block reference
                Activity::create([
                    'activity_group' => $groupId,
                    'activity_type_detail' => $activityTypeDetailId,
                    'start' => $block->start,
                    'end' => $block->end,
                    'extra_block' => $block->id,
                    'public_time' => (bool) ($block->public_time ?? false) || $atdPublicTime,
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('FreeBlockGenerator: Error in free activities insertion', [
                'plan_id' => $this->planId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Provide more specific error messages based on exception type
            $message = 'Fehler beim Einfügen der freien Aktivitäten';
            if (str_contains($e->getMessage(), "Parameter '")) {
                $message = 'Ungültiger Parameterwert in freien Blöcken';
            } elseif (str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'existiert nicht')) {
                $message = 'Fehlende Daten für freie Blöcke';
            } elseif (str_contains($e->getMessage(), 'activity_type_detail')) {
                $message = 'Fehler bei der Aktivitätstyp-Zuordnung';
            } elseif ($e instanceof \RuntimeException) {
                $message = $e->getMessage();
            } else {
                $message .= ": {$e->getMessage()}";
            }

            throw new \RuntimeException($message, 0, $e);
        }
    }
}
