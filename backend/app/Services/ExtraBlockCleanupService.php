<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityGroup;
use App\Models\ExtraBlock;
use App\Models\SlotBlockTeam;
use Illuminate\Support\Facades\DB;

/**
 * Central cleanup before removing an extra_block row (activities, slot_block_team, etc.).
 */
class ExtraBlockCleanupService
{
    public function beforeDelete(ExtraBlock $block): void
    {
        if ($block->type === 'slot') {
            $this->purgeSlotBlockArtifacts($block);

            return;
        }

        DB::table('activity')
            ->where('extra_block', $block->id)
            ->update(['extra_block' => null]);
    }

    private function purgeSlotBlockArtifacts(ExtraBlock $block): void
    {
        $planId = (int) $block->plan;
        $id = (int) $block->id;

        SlotBlockTeam::query()->where('extra_block', $id)->delete();

        $groupIds = Activity::query()
            ->where('extra_block', $id)
            ->pluck('activity_group')
            ->unique()
            ->map(fn ($v) => (int) $v)
            ->all();

        Activity::query()->where('extra_block', $id)->delete();

        if (! empty($groupIds)) {
            ActivityGroup::query()
                ->where('plan', $planId)
                ->whereIn('id', $groupIds)
                ->delete();
        }
    }
}
