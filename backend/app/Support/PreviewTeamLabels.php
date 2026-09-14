<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Plan-slot team names for Ablauf Rollen/Teams preview tooltips.
 */
final class PreviewTeamLabels
{
    public const UNREGISTERED = 'Noch nicht angemeldet';

    /**
     * @return array<int, array<int, string>> first_program => [team_number_plan => name]
     */
    public static function namesByProgramSlot(int $planId): array
    {
        if ($planId < 1) {
            return [];
        }

        $rows = DB::table('team_plan as tp')
            ->join('plan as p', 'p.id', '=', 'tp.plan')
            ->join('team as t', function ($j) {
                $j->on('t.id', '=', 'tp.team')
                    ->on('t.event', '=', 'p.event');
            })
            ->where('tp.plan', $planId)
            ->get(['t.first_program', 'tp.team_number_plan', 't.name']);

        $map = [];
        foreach ($rows as $row) {
            $fp = (int) ($row->first_program ?? 0);
            $slot = (int) ($row->team_number_plan ?? 0);
            if ($fp < 1 || $slot < 1) {
                continue;
            }
            $map[$fp][$slot] = (string) ($row->name ?? '');
        }

        return $map;
    }

    public static function tooltip(int $teamNo, ?string $name): ?string
    {
        if ($teamNo < 1) {
            return null;
        }

        $trimmed = trim((string) $name);

        return $trimmed !== '' ? $trimmed : self::UNREGISTERED;
    }

    /**
     * @param  array<int, array<int, string>>  $namesByProgramSlot
     */
    public static function tooltipFor(int $programId, int $teamNo, array $namesByProgramSlot): ?string
    {
        return self::tooltip($teamNo, $namesByProgramSlot[$programId][$teamNo] ?? null);
    }
}
