<?php

namespace App\Services;

use App\Support\ProgramPresence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DrahtProgramDetachService
{
    /**
     * Remove catalog programs no longer present in DRAHT for this event.
     *
     * @param  list<int>  $activeDrahtIds  DRAHT event ids still in the sync feed for this FLOW event.
     * @return list<int>  detached first_program ids
     */
    public function detachStaleByDrahtIds(int $eventId, array $activeDrahtIds): array
    {
        $active = array_values(array_unique(array_map('intval', $activeDrahtIds)));

        $rows = DB::table('event_program')
            ->where('event', $eventId)
            ->whereNotNull('draht_id')
            ->get(['id', 'first_program', 'draht_id']);

        $detached = [];
        foreach ($rows as $row) {
            $drahtId = (int) $row->draht_id;
            if (in_array($drahtId, $active, true)) {
                continue;
            }
            $this->detachProgram($eventId, (int) $row->first_program);
            $detached[] = (int) $row->first_program;
        }

        return $detached;
    }

    public function detachProgram(int $eventId, int $firstProgramId): void
    {
        $deleted = DB::table('event_program')
            ->where('event', $eventId)
            ->where('first_program', $firstProgramId)
            ->delete();

        if ($deleted < 1) {
            return;
        }

        $planIds = DB::table('plan')->where('event', $eventId)->pluck('id');
        foreach ($planIds as $planId) {
            ProgramPresence::purgeParametersOutsideEvent((int) $planId);
            ProgramPresence::syncChallengeShapedModes((int) $planId);
        }

        Log::info('Detached program after DRAHT removal', [
            'event_id' => $eventId,
            'first_program' => $firstProgramId,
        ]);
    }
}
