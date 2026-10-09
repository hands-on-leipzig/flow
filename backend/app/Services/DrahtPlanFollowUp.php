<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class DrahtPlanFollowUp
{
    /**
     * @return array{shiftDays: int, generate: bool}
     */
    public function decide(?string $oldDate, ?string $newDate, bool $programSetChanged): array
    {
        $old = $this->day($oldDate);
        $new = $this->day($newDate);
        $shiftDays = 0;
        if ($old !== null && $new !== null && $old !== $new) {
            $from = DateTimeImmutable::createFromFormat('!Y-m-d', $old);
            $to = DateTimeImmutable::createFromFormat('!Y-m-d', $new);
            if ($from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable) {
                $shiftDays = (int) $from->diff($to)->format('%r%a');
            }
        }

        return [
            'shiftDays' => $shiftDays,
            'generate' => $shiftDays !== 0 || $programSetChanged,
        ];
    }

    public function shiftPlan(int $planId, int $shiftDays): void
    {
        if ($shiftDays === 0) {
            return;
        }

        [$startSql, $startBindings] = $this->addDaysExpression('start', $shiftDays);
        [$endSql, $endBindings] = $this->addDaysExpression('end', $shiftDays);
        $endColumn = DB::getQueryGrammar()->wrap('end');

        DB::update(
            "update extra_block set start = {$startSql}, {$endColumn} = {$endSql} where plan = ? and start is not null",
            [...$startBindings, ...$endBindings, $planId]
        );

        [$slotSql, $slotBindings] = $this->addDaysExpression('start', $shiftDays);
        DB::update(
            "update slot_block_team set start = {$slotSql} where start is not null and extra_block in (select id from extra_block where plan = ?)",
            [...$slotBindings, $planId]
        );
    }

    public function deleteMatchesForDetachedPrograms(int $planId, int $eventId): void
    {
        $programIds = DB::table('event_program')
            ->where('event', $eventId)
            ->pluck('first_program')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($programIds === []) {
            return;
        }

        DB::table('match')
            ->where('plan', $planId)
            ->whereNotIn('first_program', $programIds)
            ->delete();
    }

    private function day(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * @return array{0: string, 1: list<int|string>}
     */
    private function addDaysExpression(string $column, int $shiftDays): array
    {
        $quoted = DB::getQueryGrammar()->wrap($column);
        if (DB::connection()->getDriverName() === 'sqlite') {
            $modifier = ($shiftDays >= 0 ? '+' : '').$shiftDays.' days';

            return ["datetime({$quoted}, ?)", [$modifier]];
        }

        return ["DATE_ADD({$quoted}, INTERVAL ? DAY)", [$shiftDays]];
    }
}
