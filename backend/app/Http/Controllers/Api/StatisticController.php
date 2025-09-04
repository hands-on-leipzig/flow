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
                'event.event_explore as event_explore', 
                'event.event_challenge as event_challenge',

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

        // Plan-IDs sammeln
        $planIds = $records->pluck('plan_id')->filter()->unique();

        // Generator-Stats abrufen
        $genStatsRaw = DB::table('s_generator')
            ->whereIn('plan', $planIds)
            ->whereNotNull('start')
            ->whereNotNull('end')
            ->select(
                'plan',
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('plan')
            ->get()
            ->keyBy('plan');
                
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
                    'event_explore' => $row->event_explore,
                    'event_challenge' => $row->event_challenge,
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
                    'generator_stats' => $genStatsRaw[$row->plan_id]->count ?? null,
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

        return response()->json([
            'seasons' => $seasons,
        ]);
    }


    public function totals(): JsonResponse
    {
        // Saisons laden (sortiert)
        $seasons = DB::table('m_season')
            ->select('id', 'name as season_name', 'year as season_year')
            ->orderBy('year')
            ->get();

        $resultSeasons = [];

        foreach ($seasons as $season) {
            $sid = $season->id;

            // --- RP: mit mind. einem Event in der Saison ---
            $rpWithEvents = DB::table('event')
                ->where('event.season', $sid)
                ->join('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
                ->distinct('event.regional_partner')
                ->count('event.regional_partner');

            // --- Events: total & Plan-Verteilung & ungültige RP-Refs ---
            $eventsTotal = DB::table('event')->where('season', $sid)->count();

            // Events je Plan-Anzahl (0/1/mehr)
            $eventPlanCounts = DB::table('event')
                ->leftJoin('plan', 'plan.event', '=', 'event.id')
                ->where('event.season', $sid)
                ->groupBy('event.id')
                ->selectRaw('event.id, COUNT(plan.id) as plan_count')
                ->pluck('plan_count');

            $withZeroPlans      = $eventPlanCounts->filter(fn ($c) => $c == 0)->count();
            $withOnePlan        = $eventPlanCounts->filter(fn ($c) => $c == 1)->count();
            $withMultiplePlans  = $eventPlanCounts->filter(fn ($c) => $c > 1)->count();

            // Events mit ungültigem RP (Left Join → RP fehlt)
            $invalidEventRp = DB::table('event')
                ->leftJoin('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
                ->where('event.season', $sid)
                ->whereNull('regional_partner.id')
                ->count('event.id');

            // --- Plans in der Saison ---
            $plansTotal = DB::table('plan')
                ->join('event', 'event.id', '=', 'plan.event')
                ->where('event.season', $sid)
                ->count('plan.id');

            // --- Activity Groups in der Saison ---
            $activityGroupsTotal = DB::table('activity_group')
                ->join('plan', 'plan.id', '=', 'activity_group.plan')
                ->join('event', 'event.id', '=', 'plan.event')
                ->where('event.season', $sid)
                ->count('activity_group.id');

            // --- Activities in der Saison ---
            $activitiesTotal = DB::table('activity')
                ->join('activity_group', 'activity_group.id', '=', 'activity.activity_group')
                ->join('plan', 'plan.id', '=', 'activity_group.plan')
                ->join('event', 'event.id', '=', 'plan.event')
                ->where('event.season', $sid)
                ->count('activity.id');

            $resultSeasons[] = [
                'season_id'    => $season->id,
                'season_name'  => $season->season_name,
                'season_year'  => $season->season_year,
                'rp' => [
                    'with_events' => $rpWithEvents,
                ],
                'events' => [
                    'total'               => $eventsTotal,
                    'with_zero_plans'     => $withZeroPlans,
                    'with_one_plan'       => $withOnePlan,
                    'with_multiple_plans' => $withMultiplePlans,
                    'invalid_partner_refs'=> $invalidEventRp,
                ],
                'plans' => [
                    'total' => $plansTotal,
                ],
                'activity_groups' => [
                    'total' => $activityGroupsTotal,
                ],
                'activities' => [
                    'total' => $activitiesTotal,
                ],
            ];
        }

        // --- Globale Waisen (saisonunabhängig) ---

        $eventsOrphans = DB::table('event')
            ->leftJoin('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
            ->where(function ($q) {
                $q->whereNull('event.regional_partner')      // FK fehlt
                ->orWhereNull('regional_partner.id');      // ungültige Referenz
            })
            ->count('event.id');

        $plansOrphans = DB::table('plan')
            ->leftJoin('event', 'event.id', '=', 'plan.event')
            ->where(function ($q) {
                $q->whereNull('plan.event')                  // FK fehlt
                ->orWhereNull('event.id');                 // ungültige Referenz
            })
            ->count('plan.id');
        
        $agOrphans = DB::table('activity_group')
            ->leftJoin('plan', 'plan.id', '=', 'activity_group.plan')
            ->where(function ($q) {
                $q->whereNull('activity_group.plan')         // FK fehlt
                ->orWhereNull('plan.id');                  // ungültige Referenz
            })
            ->count('activity_group.id');

        $actOrphans = DB::table('activity')
            ->leftJoin('activity_group', 'activity_group.id', '=', 'activity.activity_group')
            ->where(function ($q) {
                $q->whereNull('activity.activity_group')   // FK fehlt
                ->orWhereNull('activity_group.id');      // ungültige Referenz
            })
            ->count('activity.id');

        return response()->json([
            'seasons' => $resultSeasons,
            'global_orphans' => [
                'events' => [
                    'orphans' => $eventsOrphans,
                ],
                'plans' => [
                    'orphans' => $plansOrphans,
                ],
                'activity_groups' => [
                    'orphans' => $agOrphans,
                ],
                'activities' => [
                    'orphans' => $actOrphans,
                ],
            ],
        ]);
    }
}
