<?php

namespace App\Services;

use App\Enums\FirstProgram;
use App\Support\ProgramPresence;
use Illuminate\Support\Facades\DB;

final class ImportantTimesService
{
    /**
     * @return array<string, mixed>
     */
    public function forEvent(int $eventId): array
    {
        $plan = DB::table('plan')
            ->where('event', $eventId)
            ->select('id', 'last_change')
            ->first();

        if (! $plan) {
            return [
                'error' => 'Kein Plan für dieses Event gefunden',
                '_status' => 404,
            ];
        }

        return $this->buildPayload((int) $plan->id, (string) $plan->last_change);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(int $planId, string $lastChange): array
    {
        $eventId = (int) (DB::table('plan')->where('id', $planId)->value('event') ?? 0);
        $attachedProgramIds = ProgramPresence::attachedProgramIds($eventId);

        if ($attachedProgramIds === []) {
            return [
                'plan_id' => $planId,
                'last_change' => $lastChange,
                'lanes' => [],
            ];
        }

        $laneShells = $this->buildLaneShells($attachedProgramIds);
        $laneTimes = array_fill_keys(array_keys($laneShells), []);

        foreach ($this->fetchPublicActivities($planId) as $row) {
            $firstProgram = $row->first_program !== null ? (int) $row->first_program : FirstProgram::JOINT->value;
            $isJoint = $firstProgram === FirstProgram::JOINT->value;

            $entry = [
                'value' => (string) $row->start,
                'label' => (string) $row->label,
                'joint' => $isJoint,
            ];

            $targetIds = $isJoint
                ? array_keys($laneShells)
                : (isset($laneShells[$firstProgram]) ? [$firstProgram] : []);

            foreach ($targetIds as $programId) {
                $laneTimes[$programId][] = $entry;
            }
        }

        $lanes = [];
        foreach ($laneShells as $programId => $shell) {
            $times = $laneTimes[$programId] ?? [];
            if ($times === []) {
                continue;
            }

            usort($times, fn (array $a, array $b) => strtotime($a['value']) <=> strtotime($b['value']));

            $lanes[] = [
                'program_id' => $programId,
                'name' => $shell['name'],
                'sequence' => $shell['sequence'],
                'color_hex' => $shell['color_hex'],
                'times' => $times,
            ];
        }

        usort($lanes, fn (array $a, array $b) => ($a['sequence'] <=> $b['sequence']) ?: ($a['program_id'] <=> $b['program_id']));

        return [
            'plan_id' => $planId,
            'last_change' => $lastChange,
            'lanes' => $lanes,
        ];
    }

    /**
     * @param  list<int>  $attachedProgramIds
     * @return array<int, array{name: string, sequence: int, color_hex: string|null}>
     */
    private function buildLaneShells(array $attachedProgramIds): array
    {
        $rows = DB::table('m_first_program')
            ->whereIn('id', $attachedProgramIds)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get(['id', 'name', 'sequence', 'color_hex']);

        $shells = [];
        foreach ($rows as $row) {
            $shells[(int) $row->id] = [
                'name' => (string) $row->name,
                'sequence' => (int) $row->sequence,
                'color_hex' => $row->color_hex !== null ? (string) $row->color_hex : null,
            ];
        }

        return $shells;
    }

    /**
     * @return \Illuminate\Support\Collection<int, object{start: string, label: string, first_program: int|null}>
     */
    private function fetchPublicActivities(int $planId): \Illuminate\Support\Collection
    {
        return DB::table('activity as a')
            ->join('activity_group as ag', 'ag.id', '=', 'a.activity_group')
            ->join('m_activity_type_detail as atd', 'atd.id', '=', 'a.activity_type_detail')
            ->leftJoin('extra_block as peb', 'peb.id', '=', 'a.extra_block')
            ->join('plan as p', 'p.id', '=', 'ag.plan')
            ->join('event as e', 'e.id', '=', 'p.event')
            ->where('ag.plan', $planId)
            ->where('a.public_time', true)
            ->whereRaw('DATE(a.start) = DATE(e.date)')
            ->orderBy('a.start')
            ->get([
                'a.start as start',
                DB::raw('COALESCE(peb.name, atd.name_preview, atd.name) as label'),
                DB::raw('COALESCE(peb.first_program, atd.first_program) as first_program'),
            ]);
    }
}
