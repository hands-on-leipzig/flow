<?php

namespace App\Services;

use App\Models\Event;
use App\Support\EventDayClock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shifts the remainder of the running event day.
 *
 * Only `activity` rows are touched. The extra_block / slot_block_team source
 * rows keep their original times, so regenerating the plan restores them —
 * which is the documented escape hatch for this irreversible action.
 */
class CockpitTimeShiftService
{
    public const MIN_MINUTES = 5;

    public const MAX_MINUTES = 60;

    public const STEP_MINUTES = 5;

    /**
     * @return array{plan_id: int, locked: bool, current_day_date: string, now_time: string, end_of_day_time: ?string, upcoming_count: int}
     */
    public function state(Event $event): array
    {
        [$planId, $locked, $pivot] = $this->context($event);
        $day = $pivot->format('Y-m-d');

        return [
            'plan_id' => $planId,
            'locked' => $locked,
            'current_day_date' => $day,
            'now_time' => $pivot->format('H:i'),
            'end_of_day_time' => $this->endOfDayTime($planId, $day),
            'upcoming_count' => $this->upcomingQuery($planId, $day, $pivot)->count(),
        ];
    }

    /**
     * @return array{shifted_count: int, current_day_date: string, end_of_day_time: ?string}
     */
    public function shift(Event $event, int $minutes): array
    {
        [$planId, $locked, $pivot] = $this->context($event);

        if ($locked) {
            abort(423, 'Der Plan ist gesperrt.');
        }

        $day = $pivot->format('Y-m-d');

        $shifted = DB::transaction(function () use ($planId, $day, $pivot, $minutes) {
            return $this->upcomingQuery($planId, $day, $pivot)->update([
                'start' => DB::raw($this->addMinutesSql('start', $minutes)),
                'end' => DB::raw($this->addMinutesSql('end', $minutes)),
            ]);
        });

        Log::info('Cockpit timeshift applied', [
            'plan_id' => $planId,
            'minutes' => $minutes,
            'pivot' => $pivot->format('Y-m-d H:i:s'),
            'shifted_count' => $shifted,
        ]);

        return [
            'shifted_count' => $shifted,
            'current_day_date' => $day,
            'end_of_day_time' => $this->endOfDayTime($planId, $day),
        ];
    }

    /**
     * @return array{0: int, 1: bool, 2: Carbon}
     */
    private function context(Event $event): array
    {
        $plan = DB::table('plan')
            ->where('event', $event->id)
            ->select('id', 'locked')
            ->first();

        if (! $plan) {
            abort(404, 'Plan not found');
        }

        return [
            (int) $plan->id,
            (bool) ($plan->locked ?? false),
            EventDayClock::pivot(
                $event->date ? Carbon::parse($event->date)->format('Y-m-d') : null,
                max(1, (int) ($event->days ?? 1)),
            ),
        ];
    }

    /**
     * Activities of the given day that have not started yet.
     *
     * The complement (`start <= pivot`) is what actionNow treats as running or
     * past, so anything the public plan shows as running is never moved.
     */
    private function upcomingQuery(int $planId, string $day, Carbon $pivot)
    {
        return DB::table('activity')
            ->whereIn('activity_group', function ($sub) use ($planId) {
                $sub->select('id')->from('activity_group')->where('plan', $planId);
            })
            ->whereRaw('DATE(start) = ?', [$day])
            ->where('start', '>', $pivot->format('Y-m-d H:i:s'));
    }

    private function endOfDayTime(int $planId, string $day): ?string
    {
        $max = DB::table('activity')
            ->whereIn('activity_group', function ($sub) use ($planId) {
                $sub->select('id')->from('activity_group')->where('plan', $planId);
            })
            ->whereRaw('DATE(start) = ?', [$day])
            ->max('end');

        return $max ? Carbon::parse($max)->format('H:i') : null;
    }

    /**
     * Wall-clock addition on the naive column, per driver. A 5-60 minute
     * same-day shift never crosses a DST boundary, so this stays exact.
     */
    private function addMinutesSql(string $column, int $minutes): string
    {
        $quoted = DB::getQueryGrammar()->wrap($column);

        return DB::connection()->getDriverName() === 'sqlite'
            ? "datetime({$quoted}, '+{$minutes} minutes')"
            : "DATE_ADD({$quoted}, INTERVAL {$minutes} MINUTE)";
    }
}
