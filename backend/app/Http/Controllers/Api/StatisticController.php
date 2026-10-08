<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Models\Event;
use App\Services\EventTitleService;
use Carbon\Carbon;


class StatisticController extends Controller
{
    public function __construct(
        private EventTitleService $eventTitles,
    ) {}

    public function timeline(int $planId): JsonResponse
    {
        // Get plan and event data
        $plan = DB::table('plan')
            ->join('event', 'event.id', '=', 'plan.event')
            ->where('plan.id', $planId)
            ->select('plan.created as plan_created', 'event.date as event_date')
            ->first();

        if (!$plan) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        $startDate = $plan->plan_created ? \Carbon\Carbon::parse($plan->plan_created)->startOfDay() : null;
        $endDate = $plan->event_date ? \Carbon\Carbon::parse($plan->event_date)->startOfDay() : null;

        if (!$startDate || !$endDate) {
            return response()->json(['error' => 'Missing date information'], 400);
        }

        // Count generator runs per day
        $generatorRuns = DB::table('s_generator')
            ->where('plan', $planId)
            ->whereNotNull('start')
            ->select(
                DB::raw('DATE(start) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy(DB::raw('DATE(start)'))
            ->get()
            ->keyBy('date')
            ->map(fn($item) => (int)$item->count);

        // Get publication level intervals
        $publications = DB::table('publication')
            ->join('event', 'event.id', '=', 'publication.event')
            ->join('plan', 'plan.event', '=', 'event.id')
            ->where('plan.id', $planId)
            ->select('publication.level', 'publication.last_change')
            ->orderBy('publication.last_change')
            ->get();

        // Build daily data array
        $dailyData = [];
        $currentDate = $startDate->copy();
        
        while ($currentDate->lte($endDate)) {
            $dateKey = $currentDate->format('Y-m-d');
            $dailyData[] = [
                'date' => $dateKey,
                'generator_runs' => $generatorRuns->get($dateKey, 0),
            ];
            $currentDate->addDay();
        }

        // Build publication level intervals
        $today = \Carbon\Carbon::today()->startOfDay();
        $maxEndDate = $endDate->lt($today) ? $endDate->copy() : $today->copy();
        
        $publicationIntervals = [];
        foreach ($publications as $index => $pub) {
            $intervalStart = \Carbon\Carbon::parse($pub->last_change)->startOfDay();
            $intervalEnd = isset($publications[$index + 1])
                ? \Carbon\Carbon::parse($publications[$index + 1]->last_change)->startOfDay()
                : $maxEndDate->copy();
            
            // Ensure interval doesn't extend beyond today or event date
            if ($intervalEnd->gt($maxEndDate)) {
                $intervalEnd = $maxEndDate->copy();
            }
            
            // Ensure interval start doesn't extend beyond max end date
            if ($intervalStart->gt($maxEndDate)) {
                continue; // Skip intervals that start in the future
            }

            $publicationIntervals[] = [
                'level' => (int)$pub->level,
                'start_date' => $intervalStart->format('Y-m-d'),
                'end_date' => $intervalEnd->format('Y-m-d'),
            ];
        }

        return response()->json([
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'daily_data' => $dailyData,
            'publication_intervals' => $publicationIntervals,
        ]);
    }

    /**
     * Get one-link access statistics (total counts per event)
     */

    /**
     * Get one-link access chart data for a specific event
     */
    public function oneLinkAccessChart(int $eventId): JsonResponse
    {
        // Get event and plan data
        $event = DB::table('event')
            ->leftJoin('plan', 'plan.event', '=', 'event.id')
            ->where('event.id', $eventId)
            ->select(
                'event.id as event_id',
                'event.date as event_date',
                'event.days as event_days',
                'plan.created as plan_created'
            )
            ->first();

        if (!$event) {
            return response()->json(['error' => 'Event not found'], 404);
        }

        // Determine date range
        $startDate = $event->plan_created 
            ? Carbon::parse($event->plan_created)->startOfDay() 
            : Carbon::now()->startOfDay();
        $endDate = $event->event_date 
            ? Carbon::parse($event->event_date)->startOfDay() 
            : Carbon::now()->startOfDay();

        // Get daily aggregated access counts
        $dailyAccesses = DB::table('s_one_link_access')
            ->where('event', $eventId)
            ->select(
                DB::raw('DATE(access_date) as date'),
                DB::raw('COUNT(*) as access_count')
            )
            ->groupBy(DB::raw('DATE(access_date)'))
            ->get()
            ->keyBy('date')
            ->map(fn($item) => (int)$item->access_count);

        // Build daily data array
        $dailyData = [];
        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $dateKey = $currentDate->format('Y-m-d');
            $dailyData[] = [
                'date' => $dateKey,
                'access_count' => $dailyAccesses->get($dateKey, 0),
            ];
            $currentDate->addDay();
        }

        // Get publication level intervals (same as timeline chart)
        $publications = DB::table('publication')
            ->where('event', $eventId)
            ->select('level', 'last_change')
            ->orderBy('last_change')
            ->get();

        // Build publication level intervals - end at today (or event date if earlier)
        $today = Carbon::today()->startOfDay();
        $maxEndDate = $endDate->lt($today) ? $endDate->copy() : $today->copy();
        
        $publicationIntervals = [];
        foreach ($publications as $index => $pub) {
            $intervalStart = Carbon::parse($pub->last_change)->startOfDay();
            $intervalEnd = isset($publications[$index + 1])
                ? Carbon::parse($publications[$index + 1]->last_change)->startOfDay()
                : $maxEndDate->copy();
            
            // Ensure interval doesn't extend beyond today or event date
            if ($intervalEnd->gt($maxEndDate)) {
                $intervalEnd = $maxEndDate->copy();
            }
            
            // Ensure interval start doesn't extend beyond max end date
            if ($intervalStart->gt($maxEndDate)) {
                continue; // Skip intervals that start in the future
            }

            $publicationIntervals[] = [
                'level' => (int)$pub->level,
                'start_date' => $intervalStart->format('Y-m-d'),
                'end_date' => $intervalEnd->format('Y-m-d'),
            ];
        }

        // Calculate event day intervals (15-minute intervals)
        $eventDayIntervals = [];
        if ($event->event_date) {
            // Event times are in local time (Europe/Berlin)
            // Create Carbon instances - parse as UTC then add appropriate offset based on DST
            $eventDateCarbon = Carbon::parse($event->event_date)->setTime(6, 0, 0);
            $eventDays = (int)($event->event_days ?? 1);
            
            // Determine DST offset: check if event date is in DST period
            // DST in Europe: last Sunday in March (02:00 → 03:00) to last Sunday in October (03:00 → 02:00)
            // Use Carbon to check if date is in DST by creating a datetime in Europe/Berlin timezone
            $testDate = Carbon::parse($event->event_date, 'Europe/Berlin')->setTime(12, 0, 0);
            $isDST = $testDate->isDST();
            $hourOffset = $isDST ? 2 : 1; // UTC+2 in summer (CEST), UTC+1 in winter (CET)
            
            $eventStart = $eventDateCarbon->copy()->addHours($hourOffset);
            $eventEnd = Carbon::parse($event->event_date)
                ->addDays($eventDays - 1)
                ->setTime(20, 55, 0)
                ->addHours($hourOffset);

            // Get access counts for 15-minute intervals
            // access_time is stored in UTC, convert by adding offset (DST-aware)
            // Then round to nearest 15-minute interval
            $intervalAccesses = DB::table('s_one_link_access')
                ->where('event', $eventId)
                ->whereBetween('access_time', [
                    $eventStart->copy()->subHours($hourOffset), // Convert back to UTC for query
                    $eventEnd->copy()->subHours($hourOffset)
                ])
                ->select(
                    DB::raw('DATE_FORMAT(
                        DATE_ADD(
                            DATE_ADD(access_time, INTERVAL ' . $hourOffset . ' HOUR),
                            INTERVAL (15 - MINUTE(DATE_ADD(access_time, INTERVAL ' . $hourOffset . ' HOUR)) % 15) MINUTE
                        ),
                        "%Y-%m-%d %H:%i"
                    ) as interval_time'),
                    DB::raw('COUNT(*) as access_count')
                )
                ->groupBy(DB::raw('DATE_FORMAT(
                    DATE_ADD(
                        DATE_ADD(access_time, INTERVAL ' . $hourOffset . ' HOUR),
                        INTERVAL (15 - MINUTE(DATE_ADD(access_time, INTERVAL ' . $hourOffset . ' HOUR)) % 15) MINUTE
                    ),
                    "%Y-%m-%d %H:%i"
                )'))
                ->get()
                ->keyBy('interval_time')
                ->map(fn($item) => (int)$item->access_count);

            // Generate all 15-minute intervals (already shifted by hourOffset)
            $currentInterval = $eventStart->copy();
            while ($currentInterval->lte($eventEnd)) {
                $intervalKey = $currentInterval->format('Y-m-d H:i');
                
                $eventDayIntervals[] = [
                    'datetime' => $currentInterval->format('Y-m-d H:i:s'),
                    'time' => $currentInterval->format('H:i'),
                    'access_count' => $intervalAccesses->get($intervalKey, 0),
                ];
                
                $currentInterval->addMinutes(15);
            }
        }

        return response()->json([
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'event_date' => $event->event_date ? Carbon::parse($event->event_date)->format('Y-m-d') : null,
            'event_days' => (int)($event->event_days ?? 1),
            'daily_data' => $dailyData,
            'event_day_intervals' => $eventDayIntervals,
            'publication_intervals' => $publicationIntervals,
        ]);
    }

    /**
     * Check if a single event has DRAHT issues and fetch contact email
     * Called asynchronously from frontend
     */

    /**
     * Get detailed extra blocks data for a plan (for statistics / cockpit modal).
     */
    public function getExtraBlocksDetails(int $planId): JsonResponse
    {
        $eventInfo = DB::table('plan')
            ->join('event', 'event.id', '=', 'plan.event')
            ->where('plan.id', $planId)
            ->select(
                'event.id as event_id',
                'event.name as event_name',
                'event.date as event_date'
            )
            ->first();

        $titles = [];
        if ($eventInfo?->event_id) {
            $event = Event::query()->find($eventInfo->event_id);
            if ($event) {
                $titles = $this->eventTitles->titles($event);
            }
        }

        return response()->json([
            'event_id' => $eventInfo->event_id ?? null,
            'event_name' => $titles['title_short'] ?? ($eventInfo->event_name ?? null),
            'event_date' => $eventInfo->event_date ? \Carbon\Carbon::parse($eventInfo->event_date)->format('d.m.Y') : null,
            'free_blocks' => $this->freeExtraBlocks($planId),
            'slot_blocks' => $this->slotExtraBlocks($planId),
            ...$titles,
        ]);
    }

    /**
     * @return list<array{id: mixed, name: mixed, date: string|null, start: string|null, end: string|null}>
     */

    /**
     * @return list<array{id: mixed, name: mixed, date: string|null, start: string|null, end: string|null}>
     */
    private function freeExtraBlocks(int $planId): array
    {
        return DB::table('extra_block')
            ->where('plan', $planId)
            ->where('active', 1)
            ->where('type', 'free')
            ->select('id', 'name', 'start', 'end')
            ->orderBy('start')
            ->get()
            ->map(function ($block) {
                return [
                    'id' => $block->id,
                    'name' => $block->name,
                    'date' => $block->start ? Carbon::parse($block->start)->format('d.m.Y') : null,
                    'start' => $block->start ? Carbon::parse($block->start)->format('H:i') : null,
                    'end' => $block->end ? Carbon::parse($block->end)->format('H:i') : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: mixed, name: mixed, duration: int|null}>
     */

    /**
     * @return list<array{id: mixed, name: mixed, duration: int|null}>
     */
    private function slotExtraBlocks(int $planId): array
    {
        return DB::table('extra_block')
            ->where('plan', $planId)
            ->where('active', 1)
            ->where('type', 'slot')
            ->select('id', 'name', 'duration')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(function ($block) {
                return [
                    'id' => $block->id,
                    'name' => $block->name,
                    'duration' => $block->duration !== null ? (int) $block->duration : null,
                ];
            })
            ->values()
            ->all();
    }
}
