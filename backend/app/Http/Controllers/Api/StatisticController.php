<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Enums\FirstProgram;
use App\Models\Event;
use App\Http\Controllers\Api\DrahtController;
use App\Http\Controllers\Api\PlanRoomTypeController;
use App\Http\Controllers\Api\TeamController;
use Carbon\Carbon;


class StatisticController extends Controller
{  

    public function listPlans(): JsonResponse
    {
        // Alle relevanten Daten mit Joins abfragen
        $records = DB::table('regional_partner')
            ->leftJoin('event', 'event.regional_partner', '=', 'regional_partner.id')
            ->leftJoin('plan', 'plan.event', '=', 'event.id')
            ->leftJoin(DB::raw('(
                SELECT 
                    p.event, 
                    p.level, 
                    p.last_change,
                    first_pub.last_change as first_publication_date
                FROM publication p
                INNER JOIN (
                    SELECT event, MAX(last_change) as max_last_change
                    FROM publication
                    GROUP BY event
                ) latest
                ON p.event = latest.event AND p.last_change = latest.max_last_change
                INNER JOIN (
                    SELECT event, MIN(last_change) as min_last_change
                    FROM publication
                    GROUP BY event
                ) first
                ON p.event = first.event
                INNER JOIN publication first_pub
                ON first_pub.event = first.event AND first_pub.last_change = first.min_last_change
            ) as pub'), 'pub.event', '=', 'event.id')
            ->join('m_season', 'event.season', '=', 'm_season.id')
            ->where('regional_partner.name', 'not like', '%QPlan RP%')
            ->select([
                // RP
                'regional_partner.id as partner_id',
                'regional_partner.name as partner_name',
                'regional_partner.region as partner_region',

                // Event
                'event.id as event_id',
                'event.name as event_name',
                'event.date as event_date',
                'event.link as event_link',
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

                // Publication
                'pub.level as publication_level',
                'pub.first_publication_date as publication_date',
                'pub.last_change as publication_last_change',
            ])
            ->get();

        // Plan-IDs sammeln
        $planIds = $records->pluck('plan_id')->filter()->unique();

        // Team-Zahlen pro Event abrufen
        $teamCountsByEvent = DB::table('team')
            ->select('event', 'first_program', DB::raw('COUNT(*) as count'))
            ->groupBy('event', 'first_program')
            ->get()
            ->groupBy('event')
            ->map(function ($items) {
                $counts = [];
                foreach ($items as $item) {
                    $counts[(int)$item->first_program] = (int)$item->count;
                }
                return $counts;
            });

        // Fallback: Falls keine Teams in der lokalen DB vorliegen, aus DRAHT ziehen
        $eventIds = $records->pluck('event_id')->filter()->unique();
        $fallbackEventIds = $eventIds->filter(function ($id) use ($teamCountsByEvent) {
            $counts = $teamCountsByEvent->get($id);
            return empty($counts);
        });

        $drahtTeamCounts = [];
        $drahtIssues = []; // Track DRAHT issues per event
        if ($fallbackEventIds->isNotEmpty()) {
            $events = Event::whereIn('id', $fallbackEventIds)->get();
            $drahtController = app(DrahtController::class);

            foreach ($events as $event) {
                try {
                    $response = $drahtController->show($event);
                    $payload = $response->getData(true);
                    $drahtTeamCounts[$event->id] = [
                        'explore' => isset($payload['teams_explore']) && is_array($payload['teams_explore'])
                            ? count($payload['teams_explore'])
                            : 0,
                        'challenge' => isset($payload['teams_challenge']) && is_array($payload['teams_challenge'])
                            ? count($payload['teams_challenge'])
                            : 0,
                    ];
                } catch (\Throwable $e) {
                    Log::warning('StatisticController: Failed to fetch DRAHT team counts', [
                        'event_id' => $event->id,
                        'message' => $e->getMessage(),
                    ]);
                    $drahtTeamCounts[$event->id] = [
                        'explore' => 0,
                        'challenge' => 0,
                    ];
                    $drahtIssues[$event->id] = true;
                }
            }
        }

        // DRAHT checks and contact emails are now done asynchronously via separate endpoint
        // No DRAHT calls during initial load to avoid timeout

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

        // Changed Parameter Stats abrufen (nur Abweichungen vom Default)
        // Separate counts for 'input' and 'expert' contexts
        // For 'input': only includes parameters with "duration" or "start" in the name
        $paramStatsRaw = DB::table('plan_param_value as ppv')
            ->join('m_parameter as mp', 'mp.id', '=', 'ppv.parameter')
            ->whereIn('ppv.plan', $planIds)
            ->where(function ($q) {
                $q->where(function ($q2) {
                    // Expert context: all parameters
                    $q2->where('mp.context', 'expert');
                })->orWhere(function ($q2) {
                    // Input context: only "duration" or "start" in name
                    $q2->where('mp.context', 'input')
                       ->where(function ($q3) {
                           $q3->where('mp.name', 'like', '%duration%')
                              ->orWhere('mp.name', 'like', '%start%');
                       });
                });
            })
            ->where(function ($q) {
                $q->whereRaw('ppv.set_value <> mp.value')
                ->orWhere(function ($q2) {
                    $q2->whereNull('ppv.set_value')
                        ->whereNotNull('mp.value');
                })
                ->orWhere(function ($q2) {
                    $q2->whereNotNull('ppv.set_value')
                        ->whereNull('mp.value');
                });
            })
            ->select(
                'ppv.plan',
                'mp.context',
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('ppv.plan', 'mp.context')
            ->get();
        
        // Group by plan and context
        $paramStats = [];
        foreach ($paramStatsRaw as $stat) {
            if (!isset($paramStats[$stat->plan])) {
                $paramStats[$stat->plan] = ['input' => 0, 'expert' => 0];
            }
            $paramStats[$stat->plan][$stat->context] = $stat->count;
        }

        // Extra-Block-Stats abrufen (separate free and inserted blocks)
        // Free blocks: have start/end times, no insert_point
        $freeBlockStatsRaw = DB::table('extra_block')
            ->whereIn('plan', $planIds)
            ->where('active', 1)
            ->whereNotNull('start')
            ->whereNotNull('end')
            ->whereNull('insert_point')
            ->select(
                'plan',
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('plan')
            ->get()
            ->keyBy('plan');

        // Inserted blocks: have insert_point, no start/end times
        $insertedBlockStatsRaw = DB::table('extra_block')
            ->whereIn('plan', $planIds)
            ->where('active', 1)
            ->whereNotNull('insert_point')
            ->select(
                'plan',
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('plan')
            ->get()
            ->keyBy('plan');

        // Check for overwritten table names per event
        // Get event IDs for all plans
        $planEventMap = DB::table('plan')
            ->whereIn('id', $planIds)
            ->pluck('event', 'id');
        
        $eventIds = $planEventMap->values()->unique()->filter();
        
        // Check which events have overwritten table names (non-empty table_name)
        $eventsWithTableNames = DB::table('table_event')
            ->whereIn('event', $eventIds)
            ->whereNotNull('table_name')
            ->where('table_name', '!=', '')
            ->distinct()
            ->pluck('event')
            ->toArray();
        
        // Map: plan_id => has_overwritten_table_names
        $planTableNamesOverwritten = [];
        foreach ($planEventMap as $planId => $eventId) {
            $planTableNamesOverwritten[$planId] = in_array($eventId, $eventsWithTableNames);
        }

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
                $counts = $teamCountsByEvent->get($row->event_id);
                if (!empty($counts)) {
                    $exploreCount = ($counts[FirstProgram::EXPLORE->value] ?? 0) + ($counts[FirstProgram::DISCOVER->value] ?? 0);
                    $challengeCount = $counts[FirstProgram::CHALLENGE->value] ?? 0;
                } else {
                    $fallback = $drahtTeamCounts[$row->event_id] ?? ['explore' => 0, 'challenge' => 0];
                    $exploreCount = $fallback['explore'];
                    $challengeCount = $fallback['challenge'];
                }

                $groupedSeasons[$seasonKey]['partners'][$partnerKey]['events'][$eventKey] = [
                    'event_id' => $row->event_id,
                    'event_name' => $row->event_name,
                    'event_date' => $row->event_date,
                    'event_link' => $row->event_link,
                    'event_explore' => $row->event_explore,
                    'event_challenge' => $row->event_challenge,
                    'teams_explore' => $exploreCount,
                    'teams_challenge' => $challengeCount,
                    'contact_email' => null, // Will be fetched asynchronously
                    'draht_issue' => false, // Will be checked asynchronously
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
                    'expert_param_changes' => $paramStats[$row->plan_id] ?? ['input' => 0, 'expert' => 0], 
                    'extra_blocks' => [
                        'free' => $freeBlockStatsRaw[$row->plan_id]->count ?? 0,
                        'inserted' => $insertedBlockStatsRaw[$row->plan_id]->count ?? 0,
                    ], 
                    'publication_level' => $row->publication_level,
                    'publication_date' => $row->publication_date,
                    'publication_last_change' => $row->publication_last_change,
                    'has_warning' => false, // Will be set by DRAHT check
                    'has_table_names' => $planTableNamesOverwritten[$row->plan_id] ?? false,
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

            // --- RP: total (alle RPs, außer QPlan RP) ---
            $rpTotal = DB::table('regional_partner')
                ->where('regional_partner.name', 'not like', '%QPlan RP%')
                ->count();

            // --- RP: mit mind. einem Event in der Saison ---
            $rpWithEvents = DB::table('event')
                ->where('event.season', $sid)
                ->join('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
                ->where('regional_partner.name', 'not like', '%QPlan RP%')
                ->distinct('event.regional_partner')
                ->count('event.regional_partner');

            // --- Events: total & Plan-Verteilung & ungültige RP-Refs ---
            $today = Carbon::today()->format('Y-m-d');
            
            // Events total - split by past (including today) and future
            $eventsPast = DB::table('event')
                ->join('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
                ->where('event.season', $sid)
                ->where('regional_partner.name', 'not like', '%QPlan RP%')
                ->where('event.date', '<=', $today)
                ->count('event.id');
            
            $eventsFuture = DB::table('event')
                ->join('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
                ->where('event.season', $sid)
                ->where('regional_partner.name', 'not like', '%QPlan RP%')
                ->where('event.date', '>', $today)
                ->count('event.id');
            
            $eventsTotal = $eventsPast + $eventsFuture;

            // Events je Plan-Anzahl (0/1/mehr) - split by past and future
            $eventPlanCountsPast = DB::table('event')
                ->leftJoin('plan', 'plan.event', '=', 'event.id')
                ->join('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
                ->where('event.season', $sid)
                ->where('regional_partner.name', 'not like', '%QPlan RP%')
                ->where('event.date', '<=', $today)
                ->groupBy('event.id')
                ->selectRaw('event.id, COUNT(plan.id) as plan_count')
                ->pluck('plan_count');
            
            $eventPlanCountsFuture = DB::table('event')
                ->leftJoin('plan', 'plan.event', '=', 'event.id')
                ->join('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
                ->where('event.season', $sid)
                ->where('regional_partner.name', 'not like', '%QPlan RP%')
                ->where('event.date', '>', $today)
                ->groupBy('event.id')
                ->selectRaw('event.id, COUNT(plan.id) as plan_count')
                ->pluck('plan_count');

            $withZeroPlans      = $eventPlanCountsPast->filter(fn ($c) => $c == 0)->count() + $eventPlanCountsFuture->filter(fn ($c) => $c == 0)->count();
            $withOnePlan        = $eventPlanCountsPast->filter(fn ($c) => $c == 1)->count() + $eventPlanCountsFuture->filter(fn ($c) => $c == 1)->count();
            $withMultiplePlans  = $eventPlanCountsPast->filter(fn ($c) => $c > 1)->count() + $eventPlanCountsFuture->filter(fn ($c) => $c > 1)->count();
            $withPlan = $withOnePlan + $withMultiplePlans;
            
            // Events with plan - split by past and future (old calculation, kept for compatibility)
            $withPlanPast = $eventPlanCountsPast->filter(fn ($c) => $c > 0)->count();
            $withPlanFuture = $eventPlanCountsFuture->filter(fn ($c) => $c > 0)->count();
            
            // Events with plan that has at least one generator run - split by past and future
            $withPlanWithGeneratorPast = DB::table('event')
                ->join('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
                ->join('plan', 'plan.event', '=', 'event.id')
                ->join('s_generator', 's_generator.plan', '=', 'plan.id')
                ->where('event.season', $sid)
                ->where('regional_partner.name', 'not like', '%QPlan RP%')
                ->where('event.date', '<=', $today)
                ->whereNotNull('s_generator.start')
                ->whereNotNull('s_generator.end')
                ->distinct()
                ->count('event.id');
            
            $withPlanWithGeneratorFuture = DB::table('event')
                ->join('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
                ->join('plan', 'plan.event', '=', 'event.id')
                ->join('s_generator', 's_generator.plan', '=', 'plan.id')
                ->where('event.season', $sid)
                ->where('regional_partner.name', 'not like', '%QPlan RP%')
                ->where('event.date', '>', $today)
                ->whereNotNull('s_generator.start')
                ->whereNotNull('s_generator.end')
                ->distinct()
                ->count('event.id');

            // Events mit ungültigem RP (Left Join → RP fehlt)
            $invalidEventRp = DB::table('event')
                ->leftJoin('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
                ->where('event.season', $sid)
                ->whereNull('regional_partner.id')
                ->count('event.id');

            // --- Plans in der Saison ---
            $plansTotal = DB::table('plan')
                ->join('event', 'event.id', '=', 'plan.event')
                ->join('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
                ->where('event.season', $sid)
                ->where('regional_partner.name', 'not like', '%QPlan RP%')
                ->count('plan.id');

            // --- Activity Groups in der Saison ---
            $activityGroupsTotal = DB::table('activity_group')
                ->join('plan', 'plan.id', '=', 'activity_group.plan')
                ->join('event', 'event.id', '=', 'plan.event')
                ->join('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
                ->where('event.season', $sid)
                ->where('regional_partner.name', 'not like', '%QPlan RP%')
                ->count('activity_group.id');

            // --- Activities in der Saison ---
            $activitiesTotal = DB::table('activity')
                ->join('activity_group', 'activity_group.id', '=', 'activity.activity_group')
                ->join('plan', 'plan.id', '=', 'activity_group.plan')
                ->join('event', 'event.id', '=', 'plan.event')
                ->join('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
                ->where('event.season', $sid)
                ->where('regional_partner.name', 'not like', '%QPlan RP%')
                ->count('activity.id');

            $resultSeasons[] = [
                'season_id'    => $season->id,
                'season_name'  => $season->season_name,
                'season_year'  => $season->season_year,
                'rp' => [
                    'total'        => $rpTotal,
                    'with_events'  => $rpWithEvents,
                ],
                'events' => [
                    'total'                => $eventsTotal,
                    'past'                  => $eventsPast,
                    'future'                => $eventsFuture,
                    'with_zero_plans'      => $withZeroPlans,
                    'with_one_plan'        => $withOnePlan,
                    'with_multiple_plans'  => $withMultiplePlans,
                    'with_plan'            => $withPlan,
                    'with_plan_past'       => $withPlanPast,
                    'with_plan_future'     => $withPlanFuture,
                    'with_plan_with_generator_past'   => $withPlanWithGeneratorPast,
                    'with_plan_with_generator_future' => $withPlanWithGeneratorFuture,
                    'invalid_partner_refs' => $invalidEventRp,
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
            'publication_totals' => $this->publicationTotals(),
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

    protected function publicationTotals(): array
    {
        // Get latest publication per event, then count by level
        $latestPublications = DB::table('publication as p1')
            ->whereRaw('p1.last_change = (
                SELECT MAX(p2.last_change)
                FROM publication p2
                WHERE p2.event = p1.event
            )')
            ->select('p1.level')
            ->get();

        $levels = $latestPublications->groupBy('level')->map->count();

        $level1 = (int)($levels[1] ?? 0);
        $level2 = (int)($levels[2] ?? 0);
        $level3 = (int)($levels[3] ?? 0);
        $level4 = (int)($levels[4] ?? 0);

        return [
            'total' => $level1 + $level2 + $level3 + $level4,
            'level_1' => $level1,
            'level_2' => $level2,
            'level_3' => $level3,
            'level_4' => $level4,
        ];
    }

    public function cleanupOrphans(string $type): JsonResponse
    {
        $type = strtolower($type);
        $deleted = match ($type) {
            'events' => $this->deleteEventsWithoutPartner(),
            'plans' => $this->deletePlansWithoutEvent(),
            'activity-groups' => $this->deleteActivityGroupsWithoutPlan(),
            'activities' => $this->deleteActivitiesWithoutGroup(),
            default => null,
        };

        if ($deleted === null) {
            return response()->json([
                'message' => 'Unknown orphan type.',
            ], 404);
        }

        return response()->json([
            'deleted' => $deleted,
        ]);
    }

    private function deleteEventsWithoutPartner(): int
    {
        $ids = DB::table('event')
            ->leftJoin('regional_partner', 'regional_partner.id', '=', 'event.regional_partner')
            ->where(function ($q) {
                $q->whereNull('event.regional_partner')
                    ->orWhereNull('regional_partner.id');
            })
            ->pluck('event.id')
            ->all();

        if (empty($ids)) {
            return 0;
        }

        return DB::table('event')
            ->whereIn('id', $ids)
            ->delete();
    }

    private function deletePlansWithoutEvent(): int
    {
        $ids = DB::table('plan')
            ->leftJoin('event', 'event.id', '=', 'plan.event')
            ->where(function ($q) {
                $q->whereNull('plan.event')
                    ->orWhereNull('event.id');
            })
            ->pluck('plan.id')
            ->all();

        if (empty($ids)) {
            return 0;
        }

        return DB::table('plan')
            ->whereIn('id', $ids)
            ->delete();
    }

    private function deleteActivityGroupsWithoutPlan(): int
    {
        $ids = DB::table('activity_group')
            ->leftJoin('plan', 'plan.id', '=', 'activity_group.plan')
            ->where(function ($q) {
                $q->whereNull('activity_group.plan')
                    ->orWhereNull('plan.id');
            })
            ->pluck('activity_group.id')
            ->all();

        if (empty($ids)) {
            return 0;
        }

        return DB::table('activity_group')
            ->whereIn('id', $ids)
            ->delete();
    }

    private function deleteActivitiesWithoutGroup(): int
    {
        $ids = DB::table('activity')
            ->leftJoin('activity_group', 'activity_group.id', '=', 'activity.activity_group')
            ->where(function ($q) {
                $q->whereNull('activity.activity_group')
                    ->orWhereNull('activity_group.id');
            })
            ->pluck('activity.id')
            ->all();

        if (empty($ids)) {
            return 0;
        }

        return DB::table('activity')
            ->whereIn('id', $ids)
            ->delete();
    }

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
    public function oneLinkAccess(): JsonResponse
    {
        $accesses = DB::table('s_one_link_access as ola')
            ->join('event', 'event.id', '=', 'ola.event')
            ->select(
                'event.id as event_id',
                'event.slug',
                'event.name as event_name',
                DB::raw('COUNT(*) as total_count')
            )
            ->groupBy('event.id', 'event.slug', 'event.name')
            ->orderBy('total_count', 'desc')
            ->get();

        return response()->json([
            'accesses' => $accesses,
        ]);
    }

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
            $eventStart = Carbon::parse($event->event_date)->setTime(6, 0, 0);
            $eventDays = (int)($event->event_days ?? 1);
            $eventEnd = Carbon::parse($event->event_date)
                ->addDays($eventDays - 1)
                ->setTime(20, 55, 0);

            // Get access counts for 15-minute intervals
            // Round access_time to nearest 15-minute interval
            $intervalAccesses = DB::table('s_one_link_access')
                ->where('event', $eventId)
                ->whereBetween('access_time', [$eventStart, $eventEnd])
                ->select(
                    DB::raw('DATE_FORMAT(
                        DATE_ADD(
                            access_time,
                            INTERVAL (15 - MINUTE(access_time) % 15) MINUTE
                        ),
                        "%Y-%m-%d %H:%i"
                    ) as interval_time'),
                    DB::raw('COUNT(*) as access_count')
                )
                ->groupBy(DB::raw('DATE_FORMAT(
                    DATE_ADD(
                        access_time,
                        INTERVAL (15 - MINUTE(access_time) % 15) MINUTE
                    ),
                    "%Y-%m-%d %H:%i"
                )'))
                ->get()
                ->keyBy('interval_time')
                ->map(fn($item) => (int)$item->access_count);

            // Generate all 15-minute intervals
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
    public function checkDrahtIssue(int $eventId): JsonResponse
    {
        $event = Event::find($eventId);
        
        if (!$event) {
            return response()->json([
                'event_id' => $eventId,
                'has_issue' => false,
                'contact_email' => null,
                'plan_warnings' => [],
                'error' => 'Event not found'
            ], 404);
        }

        // Calculate readiness/warnings for all plans in this event
        $planWarnings = [];
        $plans = DB::table('plan')->where('event', $eventId)->get();
        
        foreach ($plans as $plan) {
            $hasWarning = $this->calculatePlanWarning($event, $plan->id);
            $planWarnings[$plan->id] = $hasWarning;
        }

        // Only check if event has DRAHT IDs
        if (!$event->event_explore && !$event->event_challenge) {
            return response()->json([
                'event_id' => $eventId,
                'has_issue' => false,
                'contact_email' => null,
                'plan_warnings' => $planWarnings,
                'message' => 'No DRAHT IDs'
            ]);
        }

        try {
            $drahtController = app(DrahtController::class);
            $response = $drahtController->show($event);
            $payload = $response->getData(true);

            // Check if scheduledata endpoint returned data successfully
            $hasExploreIssue = false;
            $hasChallengeIssue = false;

            if ($event->event_explore) {
                // If explore ID exists but no data returned, it's an issue
                if (!isset($payload['event_explore']) || $payload['event_explore'] === null) {
                    $hasExploreIssue = true;
                }
            }

            if ($event->event_challenge) {
                // If challenge ID exists but no data returned, it's an issue
                if (!isset($payload['event_challenge']) || $payload['event_challenge'] === null) {
                    $hasChallengeIssue = true;
                }
            }

            $hasIssue = $hasExploreIssue || $hasChallengeIssue;

            // Extract email from contact data (first contact only)
            $contact = $payload['contact'] ?? null;
            $email = null;
            
            if (is_array($contact) && !empty($contact)) {
                // Check if it's an array of contacts (indexed array) or a single contact (associative array)
                if (isset($contact[0]) && is_array($contact[0])) {
                    // Array of contacts - take first one
                    $firstContact = $contact[0];
                    $email = $firstContact['contact_email'] ?? $firstContact['email'] ?? $firstContact['mail'] ?? null;
                } elseif (isset($contact['contact_email'])) {
                    // Single contact object with 'contact_email' key
                    $email = $contact['contact_email'];
                } elseif (isset($contact['email'])) {
                    // Single contact object with 'email' key
                    $email = $contact['email'];
                } elseif (isset($contact['mail'])) {
                    // Single contact object with 'mail' key (alternative)
                    $email = $contact['mail'];
                } else {
                    // Try to find email in first element if it's numeric-indexed
                    $firstValue = reset($contact);
                    if (is_array($firstValue)) {
                        $email = $firstValue['contact_email'] ?? $firstValue['email'] ?? $firstValue['mail'] ?? null;
                    }
                }
            }

            return response()->json([
                'event_id' => $eventId,
                'has_issue' => $hasIssue,
                'contact_email' => $email,
                'plan_warnings' => $planWarnings,
            ]);
        } catch (\Throwable $e) {
            // If exception occurs, there's definitely an issue
            Log::warning('StatisticController: DRAHT check failed', [
                'event_id' => $eventId,
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'event_id' => $eventId,
                'has_issue' => true,
                'contact_email' => null,
                'plan_warnings' => $planWarnings,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Calculate if a plan has warnings (red dot conditions)
     * Returns true if any of: team discrepancy, missing teams, or room mapping issues
     */
    private function calculatePlanWarning(Event $event, int $planId): bool
    {
        $plan = DB::table('plan')->where('id', $planId)->first();
        if (!$plan) {
            return true; // No plan = warning
        }

        // Get planned team counts
        $paramIds = DB::table('m_parameter')
            ->whereIn('name', ['c_teams', 'e_teams'])
            ->pluck('id', 'name');

        $values = DB::table('plan_param_value')
            ->where('plan', $planId)
            ->whereIn('parameter', $paramIds->values())
            ->pluck('set_value', 'parameter')
            ->map(fn($v) => (int)$v);

        $plannedChallengeTeams = $values[$paramIds['c_teams']] ?? 0;
        $plannedExploreTeams = $values[$paramIds['e_teams']] ?? 0;

        // Get registered team counts from local DB
        $teamCounts = DB::table('team')
            ->select('first_program', DB::raw('COUNT(*) as count'))
            ->where('event', $event->id)
            ->groupBy('first_program')
            ->get()
            ->keyBy('first_program');

        $registeredChallengeTeams = (int)($teamCounts[FirstProgram::CHALLENGE->value]->count ?? 0);
        $registeredExploreTeams = (int)(($teamCounts[FirstProgram::EXPLORE->value]->count ?? 0) + ($teamCounts[FirstProgram::DISCOVER->value]->count ?? 0));

        // Check team discrepancy
        $hasTeamDiscrepancy = ($plannedChallengeTeams !== $registeredChallengeTeams) || 
                             ($plannedExploreTeams !== $registeredExploreTeams);

        $exploreTeamsOk = ($plannedExploreTeams === $registeredExploreTeams);
        $challengeTeamsOk = ($plannedChallengeTeams === $registeredChallengeTeams);

        // Check room mapping
        $planRoomTypeController = app(PlanRoomTypeController::class);
        $unmappedResponse = $planRoomTypeController->unmappedRoomTypes($planId);
        $unmappedList = $unmappedResponse->getData(true);
        $hasUnmappedRooms = !empty($unmappedList);

        // Check if all teams have rooms assigned
        $teamController = app(TeamController::class);
        $allTeamsHaveRooms = true;
        
        try {
            $requestExplore = new \Illuminate\Http\Request();
            $requestExplore->query->set('program', 'explore');
            $exploreResponse = $teamController->index($requestExplore, $event);
            $exploreTeams = collect($exploreResponse->getData(true));
            
            $requestChallenge = new \Illuminate\Http\Request();
            $requestChallenge->query->set('program', 'challenge');
            $challengeResponse = $teamController->index($requestChallenge, $event);
            $challengeTeams = collect($challengeResponse->getData(true));
            
            $exploreWithoutRoom = $exploreTeams->whereNull('room')->count();
            $challengeWithoutRoom = $challengeTeams->whereNull('room')->count();
            
            $allExploreRoomsOk = $exploreTeams->isEmpty() || $exploreWithoutRoom === 0;
            $allChallengeRoomsOk = $challengeTeams->isEmpty() || $challengeWithoutRoom === 0;
            $allTeamsHaveRooms = $allExploreRoomsOk && $allChallengeRoomsOk;
        } catch (\Exception $e) {
            $allTeamsHaveRooms = false;
        }
        
        $roomMappingOk = !$hasUnmappedRooms && $allTeamsHaveRooms;

        // Red dot shows if:
        // - hasTeamDiscrepancy (teams tab)
        // - !explore_teams_ok || !challenge_teams_ok (schedule tab)
        // - !room_mapping_ok (rooms tab)
        return $hasTeamDiscrepancy || !$exploreTeamsOk || !$challengeTeamsOk || !$roomMappingOk;
    }

    /**
     * Get detailed extra blocks data for a plan (for statistics modal)
     * Returns free blocks and inserted blocks separately
     */
    public function getExtraBlocksDetails(int $planId): JsonResponse
    {
        // Get event information for the plan
        $eventInfo = DB::table('plan')
            ->join('event', 'event.id', '=', 'plan.event')
            ->where('plan.id', $planId)
            ->select(
                'event.id as event_id',
                'event.name as event_name',
                'event.date as event_date'
            )
            ->first();

        // Free blocks: have start/end times, no insert_point
        $freeBlocks = DB::table('extra_block')
            ->where('plan', $planId)
            ->where('active', 1)
            ->whereNotNull('start')
            ->whereNotNull('end')
            ->whereNull('insert_point')
            ->select(
                'id',
                'name',
                'start',
                'end'
            )
            ->orderBy('start')
            ->get()
            ->map(function ($block) {
                return [
                    'id' => $block->id,
                    'name' => $block->name,
                    'date' => $block->start ? \Carbon\Carbon::parse($block->start)->format('d.m.Y') : null,
                    'start' => $block->start ? \Carbon\Carbon::parse($block->start)->format('H:i') : null,
                    'end' => $block->end ? \Carbon\Carbon::parse($block->end)->format('H:i') : null,
                ];
            });

        // Inserted blocks: have insert_point, no start/end times
        $insertedBlocks = DB::table('extra_block as eb')
            ->join('m_insert_point as mip', 'eb.insert_point', '=', 'mip.id')
            ->where('eb.plan', $planId)
            ->where('eb.active', 1)
            ->whereNotNull('eb.insert_point')
            ->select(
                'eb.id',
                'eb.name',
                'mip.ui_label as insert_point_name'
            )
            ->orderBy('mip.sequence')
            ->orderBy('eb.name')
            ->get()
            ->map(function ($block) {
                return [
                    'id' => $block->id,
                    'name' => $block->name,
                    'insert_point_name' => $block->insert_point_name,
                ];
            });

        return response()->json([
            'event_id' => $eventInfo->event_id ?? null,
            'event_name' => $eventInfo->event_name ?? null,
            'event_date' => $eventInfo->event_date ? \Carbon\Carbon::parse($eventInfo->event_date)->format('d.m.Y') : null,
            'free_blocks' => $freeBlocks,
            'inserted_blocks' => $insertedBlocks,
        ]);
    }
}
