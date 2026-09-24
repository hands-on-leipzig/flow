<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AudienceSchedule;
use App\Support\EventDayClock;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanActivityController extends Controller
{
    public function __construct(private AudienceSchedule $schedule) {}

    public function actionNow(int $planId, Request $req): JsonResponse
    {
        return response()->json($this->respond($planId, $req, 'now'));
    }

    public function actionNext(int $planId, Request $req): JsonResponse
    {
        return response()->json($this->respond($planId, $req, 'next'));
    }

    /**
     * @return array{plan_id: int, groups: list<array<string, mixed>>}
     */
    private function respond(int $planId, Request $req, string $window): array
    {
        $plan = DB::table('plan')
            ->join('event', 'event.id', '=', 'plan.event')
            ->where('plan.id', $planId)
            ->select('event.date as event_date', 'event.days as event_days')
            ->first();

        if (! $plan || ! $plan->event_date) {
            abort(404, 'Event not found');
        }

        $eventDate = (string) $plan->event_date;
        $eventDays = max(1, (int) ($plan->event_days ?? 1));
        $pivot = $this->pivot($req, $eventDate, $eventDays);

        [$roles, $joint, $programIds, $filterPrograms] = $this->selection($req);
        $rows = $this->schedule->rows($planId, $roles, (int) $req->query('room', 0));
        if ($filterPrograms) {
            $rows = $this->schedule->keepSelected($rows, $joint, $programIds);
        }

        $interval = (int) $req->query('interval', 30);
        if ($interval < 1) {
            $interval = 30;
        }

        $rows = $this->schedule->window($rows, $window, $pivot, $window === 'next' ? $interval : 0);

        return [
            'plan_id' => $planId,
            'groups' => $this->schedule->present($rows),
        ];
    }

    /**
     * @return array{0: list<int>, 1: bool, 2: list<int>, 3: bool}
     */
    private function selection(Request $req): array
    {
        if ($req->has('joint')) {
            $joint = (string) $req->query('joint') === '1';
            $programIds = $this->programIds($req);

            return [$this->schedule->rolesForSelection($joint, $programIds), $joint, $programIds, true];
        }

        $role = (int) $req->query('role', 14);

        return match ($role) {
            10 => [[10], true, [2], true],
            6 => [[6], true, [3], true],
            24 => [[24], true, [8], true],
            default => [[14], false, [], false],
        };
    }

    /**
     * @return list<int>
     */
    private function programIds(Request $req): array
    {
        $raw = trim((string) $req->query('programs', ''));
        if ($raw === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function pivot(Request $req, string $eventDate, int $eventDays): Carbon
    {
        $nowParam = $req->query('now');
        if (is_string($nowParam) && preg_match('/^(\d{2}|\d{4})-(\d{1,2})-(\d{1,2})[ T+](\d{1,2}):(\d{1,2})$/', urldecode(str_replace('+', ' ', $nowParam)), $m)) {
            $year = strlen($m[1]) === 2 ? '20'.$m[1] : $m[1];

            return Carbon::createFromFormat(
                'Y-m-d H:i',
                sprintf('%s-%02d-%02d %02d:%02d', $year, $m[2], $m[3], $m[4], $m[5]),
                EventDayClock::TZ
            );
        }

        $timeInput = $req->query('point_in_time');
        if (is_string($timeInput) && preg_match('/^\d{2}:\d{2}$/', $timeInput)) {
            $day = (int) $req->query('day', 1);
            $date = Carbon::parse(substr($eventDate, 0, 10), EventDayClock::TZ);
            if ($day > 1) {
                $date->addDays($day - 1);
            }

            return Carbon::createFromFormat(
                'Y-m-d H:i',
                $date->format('Y-m-d').' '.$timeInput,
                EventDayClock::TZ
            );
        }

        return EventDayClock::pivot($eventDate, $eventDays);
    }
}
