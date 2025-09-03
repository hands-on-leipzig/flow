<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class StatisticController extends Controller
{  

    public function listPlans(): JsonResponse
    {
        $plansWithEvent = DB::table('plan')
            ->join('event', 'plan.event', '=', 'event.id')
            ->join('regional_partner', 'event.regional_partner', '=', 'regional_partner.id')
            ->select([
                'plan.id as plan_id',
                'plan.name as plan_name',
                'plan.created',
                'plan.last_change',
                'plan.event as event_id',
                'plan.generator_status',

                'event.id as event_id',
                'event.name as event_name',
                'event.date as event_date',
                'event.season',

                'regional_partner.id as partner_id',
                'regional_partner.name as partner_name',
                'regional_partner.region as partner_region',
            ])
            ->get();

        $genStatsRaw = DB::table('s_generator')
            ->whereIn('plan', $plansWithEvent->pluck('plan_id'))
            ->whereNotNull('start')
            ->whereNotNull('end')
            ->select(
                'plan',
                DB::raw('COUNT(*) as count'),
                DB::raw('ROUND(AVG(TIMESTAMPDIFF(SECOND, start, end)), 2) as avg_seconds'),
                DB::raw('MIN(TIMESTAMPDIFF(SECOND, start, end)) as min_seconds'),
                DB::raw('MAX(TIMESTAMPDIFF(SECOND, start, end)) as max_seconds')
            )
            ->groupBy('plan')
            ->get()
            ->keyBy('plan');

        // Struktur: season -> partner -> event -> plans
        $groupedBySeason = [];

        foreach ($plansWithEvent as $plan) {
            $season = $plan->season;
            $partnerId = $plan->partner_id;
            $eventId = $plan->event_id;
            $planId = $plan->plan_id;

            if (!isset($groupedBySeason[$season])) {
                $groupedBySeason[$season] = [
                    'season' => $season,
                    'partners' => [],
                ];
            }

            if (!isset($groupedBySeason[$season]['partners'][$partnerId])) {
                $groupedBySeason[$season]['partners'][$partnerId] = [
                    'partner_id' => $partnerId,
                    'partner_name' => $plan->partner_name,
                    'partner_region' => $plan->partner_region,
                    'events' => [],
                ];
            }

            if (!isset($groupedBySeason[$season]['partners'][$partnerId]['events'][$eventId])) {
                $groupedBySeason[$season]['partners'][$partnerId]['events'][$eventId] = [
                    'event_id' => $eventId,
                    'event_name' => $plan->event_name,
                    'event_date' => $plan->event_date,
                    'plans' => [],
                ];
            }

            $groupedBySeason[$season]['partners'][$partnerId]['events'][$eventId]['plans'][] = [
                'plan_id' => $planId,
                'plan_name' => $plan->plan_name,
                'created' => $plan->created,
                'last_change' => $plan->last_change,
                'generator_status' => $plan->generator_status,
                'generator_stats' => $genStatsRaw[$planId] ?? null,
            ];
        }

        // Alles in arrays umwandeln
        $seasons = array_values(array_map(function ($seasonEntry) {
            $seasonEntry['partners'] = array_values(array_map(function ($partner) {
                $partner['events'] = array_values($partner['events']);
                return $partner;
            }, $seasonEntry['partners']));
            return $seasonEntry;
        }, $groupedBySeason));

        \Log::debug('StatisticsController: grouped by season', ['seasons' => $seasons]);

        return response()->json([
            'seasons' => $seasons,
        ]);
    }

}