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
        // Alle relevanten Daten mit Joins abfragen
        $records = DB::table('regional_partner')
            ->leftJoin('event', 'event.regional_partner', '=', 'regional_partner.id')
            ->leftJoin('plan', 'plan.event', '=', 'event.id')
            ->join('m_season', 'event.season', '=', 'm_season.id')
            ->where('regional_partner.id', '!=', 98)                    // Test-RP ausschließen. TODO
            ->select([
                // RP
                'regional_partner.id as partner_id',
                'regional_partner.name as partner_name',
                'regional_partner.region as partner_region',

                // Event
                'event.id as event_id',
                'event.name as event_name',
                'event.date as event_date',
                'event.season as event_season_id',

                // Season
                'm_season.id as season_id',
                'm_season.name as season_name',
                'm_season.year as season_year',

                // Plan
                'plan.id as plan_id',
                'plan.name as plan_name',
                'plan.created as plan_created',
                'plan.last_change as plan_last_change',
            ])
            ->get();

        // Gruppieren
        $groupedSeasons = [];

        foreach ($records as $row) {
            $seasonKey = $row->season_id;
            $partnerKey = $row->partner_id;
            $eventKey = $row->event_id;
            $planKey = $row->plan_id;

            // Season anlegen
            if (!isset($groupedSeasons[$seasonKey])) {
                $groupedSeasons[$seasonKey] = [
                    'season_name' => $row->season_name,
                    'season_year' => $row->season_year,
                    'partners' => [],
                ];
            }

            // Partner anlegen
            if (!isset($groupedSeasons[$seasonKey]['partners'][$partnerKey])) {
                $groupedSeasons[$seasonKey]['partners'][$partnerKey] = [
                    'partner_id' => $row->partner_id,
                    'partner_name' => $row->partner_name,
                    'partner_region' => $row->partner_region,
                    'events' => [],
                ];
            }

            // Event anlegen
            if ($row->event_id && !isset($groupedSeasons[$seasonKey]['partners'][$partnerKey]['events'][$eventKey])) {
                $groupedSeasons[$seasonKey]['partners'][$partnerKey]['events'][$eventKey] = [
                    'event_id' => $row->event_id,
                    'event_name' => $row->event_name,
                    'event_date' => $row->event_date,
                    'plans' => [],
                ];
            }

            // Plan anlegen
            if ($row->plan_id) {
                $groupedSeasons[$seasonKey]['partners'][$partnerKey]['events'][$eventKey]['plans'][] = [
                    'plan_id' => $row->plan_id,
                    'plan_name' => $row->plan_name,
                    'plan_created' => $row->plan_created,
                    'plan_last_change' => $row->plan_last_change,
                ];
            }
        }

        // Sortieren: Season → Partner → Event → Plan
        $seasons = collect($groupedSeasons)
            ->sortBy('season_year')
            ->values()
            ->map(function ($season) {
                $season['partners'] = collect($season['partners'])
                    ->sortBy('partner_name')
                    ->values()
                    ->map(function ($partner) {
                        $partner['events'] = collect($partner['events'])
                            ->sortBy('event_date')
                            ->values()
                            ->map(function ($event) {
                                $event['plans'] = collect($event['plans'])
                                    ->sortByDesc('plan_last_change')
                                    ->values()
                                    ->all();
                                return $event;
                            })
                            ->all();
                        return $partner;
                    })
                    ->all();
                return $season;
            })
            ->all();

        // Debug-Ausgabe
     //   \Log::debug('StatisticsController: plans_by_season JSON', ['seasons' => $seasons]);

        return response()->json([
            'seasons' => $seasons,
        ]);
    }

    public function plansDetails(): JsonResponse
    {
/*
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
*/
        return response()->json(['message' => 'Not implemented'], 501);
    }

}