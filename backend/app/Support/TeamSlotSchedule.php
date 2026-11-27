<?php

namespace App\Support;

use App\Enums\FirstProgram;
use Illuminate\Support\Facades\DB;

/**
 * What a team does on the plan, by plan slot (team_number_plan). Activities belong to the slot,
 * not to the team, so reordering teams only changes which team sits in which slot.
 */
final class TeamSlotSchedule
{
    /** m_visibility role that sees a program's own team activities. */
    public static function visibilityRole(int $teamFirstProgram): int
    {
        if (in_array($teamFirstProgram, [FirstProgram::DISCOVER->value, FirstProgram::EXPLORE->value], true)) {
            return 8; // Explore teams
        }

        if ($teamFirstProgram === FirstProgram::FUTURE_8->value) {
            return 21; // Future 8+ teams
        }

        return 3; // Challenge teams
    }

    public static function transferMinutes(PlanParameter $params, ProgramPresence $presence, int $programId): int
    {
        if (in_array($programId, [FirstProgram::DISCOVER->value, FirstProgram::EXPLORE->value], true)) {
            if (! $presence->exploreOn()) {
                throw new \InvalidArgumentException('Explore transfer requested for program not on plan');
            }

            return (int) $params->get('e_duration_transfer');
        }

        if ($programId === FirstProgram::CHALLENGE->value) {
            if (! $presence->challengeShapedOn(FirstProgram::CHALLENGE->value)) {
                throw new \InvalidArgumentException('Challenge transfer requested for program not on plan');
            }

            return (int) $params->get('c_duration_transfer');
        }

        if ($programId === FirstProgram::FUTURE_8->value) {
            if (! $presence->challengeShapedOn(FirstProgram::FUTURE_8->value)) {
                throw new \InvalidArgumentException('Future 8 transfer requested for program not on plan');
            }

            return (int) $params->get('f8_duration_transfer');
        }

        throw new \InvalidArgumentException("Unsupported program for transfer duration: {$programId}");
    }

    /**
     * Team-visible activities of one program, grouped by plan slot.
     *
     * @return array<int, list<array{start: string, end: string, label: string}>>
     */
    public static function bySlot(int $planId, int $programId): array
    {
        $rows = DB::table('activity as a')
            ->join('activity_group as ag', 'ag.id', '=', 'a.activity_group')
            ->join('m_activity_type_detail as atd', 'atd.id', '=', 'a.activity_type_detail')
            ->leftJoin('extra_block as eb', 'eb.id', '=', 'a.extra_block')
            ->where('ag.plan', $planId)
            ->where('atd.first_program', $programId)
            ->whereExists(function ($q) use ($programId) {
                $q->select(DB::raw(1))
                    ->from('m_visibility as mv')
                    ->whereColumn('mv.activity_type_detail', 'atd.id')
                    ->where('mv.role', self::visibilityRole($programId));
            })
            ->where(function ($q) {
                $q->whereNotNull('a.jury_team')
                    ->orWhereNotNull('a.table_1_team')
                    ->orWhereNotNull('a.table_2_team')
                    ->orWhereNotNull('a.slot_team');
            })
            ->select([
                'a.start',
                'a.end',
                'a.jury_team',
                'a.table_1_team',
                'a.table_2_team',
                'a.slot_team',
                'atd.name as activity_name',
                'atd.code as activity_code',
                'eb.name as extra_block_name',
            ])
            ->orderBy('a.start')
            ->get();

        $bySlot = [];
        foreach ($rows as $row) {
            $entry = [
                'start' => self::normalizeDateTime($row->start),
                'end' => self::normalizeDateTime($row->end),
                'label' => str_contains((string) ($row->activity_code ?? ''), '_slot_block') && (string) ($row->extra_block_name ?? '') !== ''
                    ? (string) $row->extra_block_name
                    : (string) ($row->activity_name ?: $row->activity_code),
            ];
            $slots = array_unique(array_filter([
                (int) $row->jury_team,
                (int) $row->table_1_team,
                (int) $row->table_2_team,
                (int) $row->slot_team,
            ]));
            foreach ($slots as $slot) {
                $bySlot[$slot][] = $entry;
            }
        }

        return $bySlot;
    }

    public static function normalizeDateTime(mixed $value): string
    {
        $text = preg_replace('/T/', ' ', (string) $value, 1);

        return strlen($text) === 16 ? $text.':00' : $text;
    }
}
