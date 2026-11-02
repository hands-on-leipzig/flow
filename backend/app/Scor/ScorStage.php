<?php

namespace App\Scor;

use App\Enums\FirstProgram;
use App\Models\Event;
use App\Services\CockpitStagePresentationService;
use App\Support\PlanParameter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jury groups mark noteworthy teams. The event admin picks who goes on stage,
 * within the count FLOW stored as f8_presentations.
 */
final class ScorStage
{
    /**
     * @return array{slots: int, noteworthy: list<int>, picks: list<int>}
     */
    public function state(int $eventId): array
    {
        return [
            'slots' => $this->slots($eventId),
            'noteworthy' => $this->noteworthy($eventId),
            'picks' => $this->picks($eventId),
        ];
    }

    public function tag(int $eventId, int $teamId, bool $on): array
    {
        if (!Schema::hasTable('scor_noteworthy')) {
            throw new ScorAccessException('Bühnenmarkierung ist noch nicht eingerichtet.', 503);
        }
        if ($on) {
            DB::table('scor_noteworthy')->insertOrIgnore([
                'event' => $eventId,
                'team' => $teamId,
            ]);
        } else {
            DB::table('scor_noteworthy')->where('event', $eventId)->where('team', $teamId)->delete();
            $this->forgetPick($eventId, $teamId);
        }

        return $this->state($eventId);
    }

    /**
     * @param  list<int>  $teamIds
     */
    public function pick(int $eventId, array $teamIds): array
    {
        $teamIds = array_values(array_unique(array_map('intval', $teamIds)));
        $slots = $this->slots($eventId);
        if ($slots < 1) {
            throw new ScorAccessException('FLOW hat für diese Veranstaltung keine Bühnenplätze.', 422);
        }
        if (count($teamIds) > $slots) {
            throw new ScorAccessException('Mehr Teams als Bühnenplätze.', 422);
        }
        $noted = $this->noteworthy($eventId);
        foreach ($teamIds as $teamId) {
            if (!in_array($teamId, $noted, true)) {
                throw new ScorAccessException('Auf die Bühne kommen nur Teams, die eine Jurygruppe als bemerkenswert markiert hat.', 422);
            }
        }

        $this->writePicks($eventId, $teamIds);
        $this->pushToFlow($eventId, $teamIds, $slots);

        return $this->state($eventId);
    }

    public function slots(int $eventId): int
    {
        $planId = DB::table('plan')->where('event', $eventId)->orderByDesc('id')->value('id');
        if (!$planId || !Schema::hasTable('m_parameter')) {
            return 0;
        }
        try {
            return max(0, (int) PlanParameter::load((int) $planId)->get('f8_presentations', 0));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return list<int>
     */
    private function noteworthy(int $eventId): array
    {
        if (!Schema::hasTable('scor_noteworthy')) {
            return [];
        }

        return DB::table('scor_noteworthy')
            ->where('event', $eventId)
            ->orderBy('team')
            ->pluck('team')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function picks(int $eventId): array
    {
        if (!Schema::hasTable('stage_presentation') || !Schema::hasTable('stage_presentation_team')) {
            return $this->localPicks($eventId);
        }
        $stageId = DB::table('stage_presentation')
            ->where('event', $eventId)
            ->where('first_program', FirstProgram::FUTURE_8->value)
            ->value('id');
        if (!$stageId) {
            return $this->localPicks($eventId);
        }

        return DB::table('stage_presentation_team')
            ->where('stage_presentation', $stageId)
            ->whereNotNull('team')
            ->orderBy('slot')
            ->pluck('team')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function localPicks(int $eventId): array
    {
        if (!Schema::hasTable('scor_stage_pick')) {
            return [];
        }

        return DB::table('scor_stage_pick')
            ->where('event', $eventId)
            ->orderBy('slot')
            ->pluck('team')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $teamIds
     */
    private function writePicks(int $eventId, array $teamIds): void
    {
        if (!Schema::hasTable('scor_stage_pick')) {
            return;
        }
        DB::table('scor_stage_pick')->where('event', $eventId)->delete();
        foreach ($teamIds as $index => $teamId) {
            DB::table('scor_stage_pick')->insert([
                'event' => $eventId,
                'slot' => $index + 1,
                'team' => $teamId,
            ]);
        }
    }

    private function forgetPick(int $eventId, int $teamId): void
    {
        if (Schema::hasTable('scor_stage_pick')) {
            DB::table('scor_stage_pick')->where('event', $eventId)->where('team', $teamId)->delete();
        }
    }

    /**
     * @param  list<int>  $teamIds
     */
    private function pushToFlow(int $eventId, array $teamIds, int $slots): void
    {
        if (!Schema::hasTable('stage_presentation')) {
            return;
        }
        $event = Event::query()->find($eventId);
        if (!$event) {
            return;
        }
        $padded = $teamIds;
        while (count($padded) < $slots) {
            $padded[] = null;
        }
        try {
            app(CockpitStagePresentationService::class)->saveSelection($event, 'FUTURE_8', $padded);
        } catch (\Throwable) {
            // FLOW's stage row stays as it was. SCOR still keeps scor_stage_pick.
        }
    }
}
