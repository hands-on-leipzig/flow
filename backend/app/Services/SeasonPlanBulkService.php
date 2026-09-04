<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class SeasonPlanBulkService
{
    public function __construct(
        private readonly PlanGeneratorService $generator,
    ) {}

    /**
     * Highest m_season.id — the current season for Wartung bulk actions.
     *
     * @return array{season_id: int, season_name: string, season_year: mixed, plans: int, locked: int}
     */
    public function summary(): array
    {
        $season = $this->currentSeason();
        $plans = $this->plansForSeason((int) $season->id);

        return $this->seasonPayload($season, $plans);
    }

    /**
     * Wipe generated schedule content; keep plan rows, params, extra blocks, lock.
     *
     * @return array{success: bool, season_id: int, season_name: string, season_year: mixed, plans: int, locked: int, activity_groups_deleted: int, matches_deleted: int}
     */
    public function empty(): array
    {
        $season = $this->currentSeason();
        $plans = $this->plansForSeason((int) $season->id);
        $planIds = $plans->pluck('id')->map(fn ($id) => (int) $id)->all();

        $activityGroupsDeleted = 0;
        $matchesDeleted = 0;

        if ($planIds !== []) {
            $activityGroupsDeleted = DB::table('activity_group')->whereIn('plan', $planIds)->delete();
            if (Schema::hasTable('match')) {
                $matchesDeleted = DB::table('match')->whereIn('plan', $planIds)->delete();
            }
            DB::table('plan')->whereIn('id', $planIds)->update([
                'generator_status' => null,
                'last_change' => Carbon::now(),
            ]);
        }

        Log::info('SeasonPlanBulkService::empty', [
            'season_id' => (int) $season->id,
            'plans' => count($planIds),
            'activity_groups_deleted' => $activityGroupsDeleted,
            'matches_deleted' => $matchesDeleted,
        ]);

        return [
            ...$this->seasonPayload($season, $plans),
            'success' => true,
            'activity_groups_deleted' => $activityGroupsDeleted,
            'matches_deleted' => $matchesDeleted,
        ];
    }

    /**
     * Run the generator synchronously, one plan after another. Locked plans included.
     * Unsupported configurations are skipped. Failures do not abort the rest.
     *
     * @return array{success: bool, season_id: int, season_name: string, season_year: mixed, plans: int, locked: int, regenerated: int, skipped_unsupported: int, failed: int, errors: list<array{plan_id: int, message: string}>}
     */
    public function regenerate(?int $userId = null): array
    {
        $season = $this->currentSeason();
        $plans = $this->plansForSeason((int) $season->id);

        $regenerated = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($plans as $plan) {
            $planId = (int) $plan->id;
            try {
                $support = $this->generator->isSupported($planId);
                if (! ($support['supported'] ?? false)) {
                    $skipped++;
                    continue;
                }

                $this->generator->prepare($planId, 'direct', $userId);
                $this->generator->run($planId);
                $this->afterGenerate($planId, (int) $plan->event);
                $regenerated++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = [
                    'plan_id' => $planId,
                    'message' => $e->getMessage(),
                ];
                Log::error('SeasonPlanBulkService::regenerate plan failed', [
                    'plan_id' => $planId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('SeasonPlanBulkService::regenerate', [
            'season_id' => (int) $season->id,
            'plans' => $plans->count(),
            'regenerated' => $regenerated,
            'skipped_unsupported' => $skipped,
            'failed' => $failed,
        ]);

        return [
            ...$this->seasonPayload($season, $plans),
            'success' => true,
            'regenerated' => $regenerated,
            'skipped_unsupported' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * @return object{id: mixed, name?: mixed, year?: mixed}
     */
    private function currentSeason(): object
    {
        $season = DB::table('m_season')->orderByDesc('id')->first();
        if ($season === null) {
            throw new RuntimeException('Keine Saison gefunden.');
        }

        return $season;
    }

    /**
     * @return Collection<int, object>
     */
    private function plansForSeason(int $seasonId): Collection
    {
        return DB::table('plan')
            ->join('event', 'event.id', '=', 'plan.event')
            ->where('event.season', $seasonId)
            ->select('plan.id', 'plan.locked', 'plan.event')
            ->orderBy('plan.id')
            ->get();
    }

    /**
     * @param  Collection<int, object>  $plans
     * @return array{season_id: int, season_name: string, season_year: mixed, plans: int, locked: int}
     */
    private function seasonPayload(object $season, Collection $plans): array
    {
        return [
            'season_id' => (int) $season->id,
            'season_name' => (string) ($season->name ?? ''),
            'season_year' => $season->year ?? null,
            'plans' => $plans->count(),
            'locked' => $plans->filter(fn ($plan) => (bool) $plan->locked)->count(),
        ];
    }

    private function afterGenerate(int $planId, int $eventId): void
    {
        if ($eventId < 1) {
            return;
        }

        try {
            app(EventAttentionService::class)->updateEventAttentionStatus($eventId);
            app(CalendarFeedService::class)->rebuildSafely($eventId);
            if (config('staffing.sync_after_generate')) {
                try {
                    app(StaffingSyncService::class)->syncForEvent($eventId);
                } catch (Throwable $e) {
                    Log::warning('Staffing sync after bulk generation failed', [
                        'event' => $eventId,
                        'plan' => $planId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Post-generate hooks after bulk generation failed', [
                'event' => $eventId,
                'plan' => $planId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
