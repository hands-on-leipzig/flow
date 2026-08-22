<?php

namespace App\Services;

use App\Models\AfternoonBlockOrder;
use App\Models\Plan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AfternoonBlockOrderService
{
    public function catalogBlocks(int $planId): Collection
    {
        $eventLevel = $this->eventLevel($planId);

        return DB::table('m_activity_type_detail as d')
            ->leftJoin('m_first_program as p', 'd.first_program', '=', 'p.id')
            ->join('m_parameter as mp', 'd.afternoon_parameter', '=', 'mp.id')
            ->whereNotNull('d.afternoon_chain')
            ->where('mp.context', 'afternoon')
            ->where('mp.level', '<=', $eventLevel)
            ->orderBy('d.afternoon_default')
            ->orderBy('d.id')
            ->get([
                'd.id',
                'd.code',
                'd.name',
                'd.name_preview',
                'd.afternoon_chain',
                'd.afternoon_default',
                'd.afternoon_parameter',
                'd.first_program',
                'p.name as program',
            ])
            ->map(function ($block) {
                $block->id = (int) $block->id;
                $block->afternoon_chain = (int) $block->afternoon_chain;
                $block->afternoon_default = $block->afternoon_default !== null
                    ? (int) $block->afternoon_default
                    : null;
                $block->afternoon_parameter = $block->afternoon_parameter !== null
                    ? (int) $block->afternoon_parameter
                    : null;
                $block->first_program = $block->first_program !== null ? (int) $block->first_program : null;
                return $block;
            });
    }

    public function resolvedBlocks(int $planId): Collection
    {
        $catalog = $this->catalogBlocks($planId);
        $savedIds = AfternoonBlockOrder::query()
            ->where('plan', $planId)
            ->orderBy('sequence')
            ->orderBy('id')
            ->pluck('activity_type_detail')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($savedIds === []) {
            return $catalog;
        }

        return $this->mergeSavedOrder($catalog, $savedIds);
    }

    public function saveOrder(int $planId, array $ids): Collection
    {
        Plan::findOrFail($planId);

        $catalog = $this->catalogBlocks($planId);
        $resolved = $this->mergeSavedOrder($catalog, array_map('intval', $ids));

        $catalogIds = $catalog->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $resolvedIds = $resolved->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        DB::transaction(function () use ($planId, $catalogIds, $resolvedIds) {
            AfternoonBlockOrder::query()->where('plan', $planId)->delete();

            if ($resolvedIds === $catalogIds) {
                return;
            }

            $rows = [];
            foreach ($resolvedIds as $sequence => $detailId) {
                $rows[] = [
                    'plan' => $planId,
                    'activity_type_detail' => $detailId,
                    'sequence' => $sequence,
                ];
            }
            AfternoonBlockOrder::query()->insert($rows);
        });

        return $resolved;
    }

    /**
     * Keep saved ids that still exist in catalog, then append new catalog blocks
     * in afternoon_default order. Repair afternoon_chain inversions afterwards.
     *
     * @param  list<int>  $savedIds
     */
    private function mergeSavedOrder(Collection $catalog, array $savedIds): Collection
    {
        $byId = $catalog->keyBy(fn ($block) => (int) $block->id);
        $used = [];
        $result = collect();

        foreach ($savedIds as $id) {
            $block = $byId->get($id);
            if ($block && ! isset($used[$id])) {
                $result->push($block);
                $used[$id] = true;
            }
        }

        foreach ($catalog as $block) {
            $id = (int) $block->id;
            if (! isset($used[$id])) {
                $result->push($block);
            }
        }

        return $this->repairChains($result);
    }

    private function repairChains(Collection $blocks): Collection
    {
        $list = $blocks->values()->all();
        $limit = max(1, count($list) * count($list));

        for ($n = 0; $n < $limit; $n++) {
            $indexById = [];
            foreach ($list as $i => $block) {
                $indexById[(int) $block->id] = $i;
            }

            $moved = false;
            foreach ($list as $i => $block) {
                $previousId = (int) $block->afternoon_chain;
                if ($previousId === 0 || ! isset($indexById[$previousId])) {
                    continue;
                }
                $previousIndex = $indexById[$previousId];
                if ($i > $previousIndex) {
                    continue;
                }

                $item = array_splice($list, $i, 1)[0];
                $insertAt = $i < $previousIndex ? $previousIndex : $previousIndex + 1;
                array_splice($list, $insertAt, 0, [$item]);
                $moved = true;
                break;
            }

            if (! $moved) {
                break;
            }
        }

        return collect($list)->values();
    }

    private function eventLevel(int $planId): int
    {
        $level = Plan::query()
            ->where('plan.id', $planId)
            ->join('event', 'plan.event', '=', 'event.id')
            ->value('event.level');

        return (int) $level;
    }
}
