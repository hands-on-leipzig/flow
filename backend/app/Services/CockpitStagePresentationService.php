<?php

namespace App\Services;

use App\Models\Event;
use App\Models\FirstProgram;
use App\Support\PlanParameter;
use App\Support\ProgramCatalog;
use App\Support\ProgramPresence;
use Illuminate\Support\Facades\DB;

/**
 * "Forschung auf der Bühne": which teams present on stage, per program.
 *
 * The jury enters the teams and the Moderator / Stage Crew read the same
 * screen, so selections persist on every change and the per-program lock is
 * what marks a selection as final.
 *
 * Programs are addressed by m_first_program.name throughout; integer ids only
 * ever come from the database and are used as foreign keys.
 */
class CockpitStagePresentationService
{
    /**
     * Program name -> the plan parameter holding the number of presentations.
     * A program that is on but missing here simply has no stage section.
     *
     * @var array<string, string>
     */
    private const PRESENTATION_PARAM = [
        'CHALLENGE' => 'c_presentations',
        'FUTURE_8' => 'f8_presentations',
    ];

    /**
     * @return array{has_plan: bool, programs: list<array<string, mixed>>}
     */
    public function state(Event $event): array
    {
        $plan = $this->plan($event);
        if (! $plan) {
            return ['has_plan' => false, 'programs' => []];
        }

        $params = PlanParameter::load($plan->id);
        $presence = ProgramPresence::forPlan($plan->id, $params);

        $programs = [];
        foreach ($presence->challengeShapedOnIds() as $programId) {
            $program = FirstProgram::find($programId);
            if (! $program) {
                continue;
            }

            $slots = $this->presentationCount($params, $program);
            if ($slots < 1) {
                continue;
            }

            $programs[] = $this->section($event, (int) $plan->id, $program, $slots);
        }

        return ['has_plan' => true, 'programs' => $programs];
    }

    /**
     * @param  list<int|null>  $teamIds  Positional, index 0 is slot 1.
     * @return array{has_plan: bool, programs: list<array<string, mixed>>}
     */
    public function saveSelection(Event $event, string $programName, array $teamIds): array
    {
        [, $program, $slots] = $this->target($event, $programName);

        $stageId = $this->stageRowId($event, $program);
        if ($this->isLocked($stageId)) {
            abort(423, 'Die Auswahl ist gesperrt.');
        }

        if (count($teamIds) > $slots) {
            abort(422, 'Mehr Teams als Präsentationen.');
        }

        $teamIds = array_map(
            fn ($id) => $id === null || $id === '' ? null : (int) $id,
            array_values($teamIds)
        );

        $picked = array_values(array_filter($teamIds, fn (?int $id) => $id !== null));
        if (count($picked) !== count(array_unique($picked))) {
            abort(422, 'Ein Team kann nur einmal auftreten.');
        }

        if ($picked !== []) {
            // Ownership, not eligibility: a team marked no-show after being
            // picked must still survive a re-save of the other slots.
            $owned = DB::table('team')
                ->where('event', $event->id)
                ->where('first_program', $program->id)
                ->whereIn('id', $picked)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (count($owned) !== count($picked)) {
                abort(422, 'Unbekanntes Team für dieses Programm.');
            }
        }

        DB::transaction(function () use ($stageId, $teamIds, $slots) {
            DB::table('stage_presentation_team')
                ->where('stage_presentation', $stageId)
                ->where('slot', '>', $slots)
                ->delete();

            foreach ($teamIds as $index => $teamId) {
                DB::table('stage_presentation_team')->updateOrInsert(
                    ['stage_presentation' => $stageId, 'slot' => $index + 1],
                    ['team' => $teamId],
                );
            }

            DB::table('stage_presentation')->where('id', $stageId)->update(['updated_at' => now()]);
        });

        return $this->state($event);
    }

    /**
     * @return array{has_plan: bool, programs: list<array<string, mixed>>}
     */
    public function setLock(Event $event, string $programName, bool $locked): array
    {
        [, $program] = $this->target($event, $programName);

        $stageId = $this->stageRowId($event, $program);
        DB::table('stage_presentation')->where('id', $stageId)->update([
            'locked' => $locked,
            'updated_at' => now(),
        ]);

        return $this->state($event);
    }

    /**
     * Resolve and authorize a write against a program name.
     *
     * @return array{0: object, 1: FirstProgram, 2: int}
     */
    private function target(Event $event, string $programName): array
    {
        $plan = $this->plan($event);
        if (! $plan) {
            abort(404, 'Plan not found');
        }

        $program = ProgramCatalog::resolve($programName);
        if (! $program || ! isset(self::PRESENTATION_PARAM[strtoupper((string) $program->name)])) {
            abort(422, 'Unbekanntes Programm.');
        }

        $params = PlanParameter::load($plan->id);
        $presence = ProgramPresence::forPlan($plan->id, $params);
        $slots = $this->presentationCount($params, $program);

        if (! $presence->challengeShapedOn((int) $program->id) || $slots < 1) {
            abort(422, 'Für dieses Programm sind keine Präsentationen geplant.');
        }

        return [$plan, $program, $slots];
    }

    /**
     * @return array<string, mixed>
     */
    private function section(Event $event, int $planId, FirstProgram $program, int $slots): array
    {
        $stageId = DB::table('stage_presentation')
            ->where('event', $event->id)
            ->where('first_program', $program->id)
            ->value('id');

        $bySlot = [];
        if ($stageId) {
            $rows = DB::table('stage_presentation_team')
                ->where('stage_presentation', $stageId)
                ->get(['slot', 'team']);

            foreach ($rows as $row) {
                $bySlot[(int) $row->slot] = $row->team !== null ? (int) $row->team : null;
            }
        }

        $names = [];
        $pickedIds = array_values(array_filter($bySlot, fn (?int $id) => $id !== null));
        if ($pickedIds !== []) {
            foreach (DB::table('team')->whereIn('id', $pickedIds)->get(['id', 'name']) as $row) {
                $names[(int) $row->id] = (string) $row->name;
            }
        }

        $slotList = [];
        for ($slot = 1; $slot <= $slots; $slot++) {
            $teamId = $bySlot[$slot] ?? null;
            $slotList[] = [
                'slot' => $slot,
                'team' => $teamId,
                // Carried separately so a team dropped from the options (marked
                // no-show after being picked) still renders with its name.
                'team_name' => $teamId !== null ? ($names[$teamId] ?? null) : null,
            ];
        }

        return [
            'program' => (string) $program->name,
            'program_label' => ProgramCatalog::displayName((string) $program->name, (string) $program->name),
            'logo_stem' => $program->logo_stem ?: null,
            'presentations' => $slots,
            'locked' => $stageId ? $this->isLocked((int) $stageId) : false,
            'teams' => $this->teamOptions($event, $planId, $program),
            'slots' => $slotList,
        ];
    }

    /**
     * Teams that can still be picked: this event, this program, not a no-show.
     *
     * @return list<array{id: int, name: string}>
     */
    private function teamOptions(Event $event, int $planId, FirstProgram $program): array
    {
        return DB::table('team')
            ->leftJoin('team_plan', function ($join) use ($planId) {
                $join->on('team.id', '=', 'team_plan.team')
                    ->where('team_plan.plan', '=', $planId);
            })
            ->where('team.event', $event->id)
            ->where('team.first_program', $program->id)
            ->where(function ($q) {
                $q->whereNull('team_plan.noshow')->orWhere('team_plan.noshow', '!=', 1);
            })
            ->orderBy('team.name')
            ->orderBy('team.team_number_hot')
            ->select('team.id', 'team.name')
            ->get()
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();
    }

    private function presentationCount(PlanParameter $params, FirstProgram $program): int
    {
        $name = self::PRESENTATION_PARAM[strtoupper((string) $program->name)] ?? null;
        if ($name === null || ! $params->has($name)) {
            return 0;
        }

        return max(0, (int) $params->get($name, 0));
    }

    private function stageRowId(Event $event, FirstProgram $program): int
    {
        $id = DB::table('stage_presentation')
            ->where('event', $event->id)
            ->where('first_program', $program->id)
            ->value('id');

        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('stage_presentation')->insertGetId([
            'event' => $event->id,
            'first_program' => $program->id,
            'locked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function isLocked(int $stageId): bool
    {
        return (bool) DB::table('stage_presentation')->where('id', $stageId)->value('locked');
    }

    private function plan(Event $event): ?object
    {
        return DB::table('plan')
            ->where('event', $event->id)
            ->orderBy('id')
            ->select('id')
            ->first();
    }
}
