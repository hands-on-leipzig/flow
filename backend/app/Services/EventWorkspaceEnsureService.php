<?php

namespace App\Services;

use App\Http\Controllers\Api\PlanController;
use App\Models\EventStaffingRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Silently ensure an event has an Ablauf (when missing) and staffing roles.
 * Used by Overview so users are not prompted; callers may toast on side effects.
 */
class EventWorkspaceEnsureService
{
    public function __construct(
        private PlanGeneratorService $generator,
        private StaffingSyncService $staffing,
    ) {}

    /**
     * @return array{
     *     plan_id: int,
     *     existing: bool,
     *     generated: bool,
     *     staffing_synced: bool,
     *     locked: bool,
     *     generate_skipped: ?string
     * }
     */
    public function ensure(int $eventId, ?int $userId = null): array
    {
        $planMeta = $this->ensurePlanRow($eventId);
        $planId = (int) $planMeta['id'];
        $locked = (bool) ($planMeta['locked'] ?? false);
        $hasActivities = (bool) ($planMeta['existing'] ?? false);

        $generated = false;
        $generateSkipped = null;

        if (! $hasActivities) {
            if ($locked) {
                $generateSkipped = 'locked';
            } else {
                $support = $this->generator->isSupported($planId);
                if (! ($support['supported'] ?? false)) {
                    $generateSkipped = 'unsupported';
                    Log::info('Overview ensure skipped generate (unsupported)', [
                        'event_id' => $eventId,
                        'plan_id' => $planId,
                        'details' => $support['details'] ?? $support['error'] ?? null,
                    ]);
                } else {
                    $this->generator->prepare($planId, 'direct', $userId);
                    $this->generator->run($planId);
                    $this->afterGenerate($eventId, $planId);
                    $generated = true;
                    $hasActivities = DB::table('activity_group')->where('plan', $planId)->exists();
                }
            }
        }

        $staffingSynced = false;
        $roleCount = EventStaffingRole::query()->where('event', $eventId)->count();
        if ($roleCount === 0) {
            try {
                $this->staffing->syncForEvent($eventId);
                $staffingSynced = true;
            } catch (\Throwable $e) {
                Log::warning('Overview ensure staffing sync failed', [
                    'event_id' => $eventId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'plan_id' => $planId,
            'existing' => $hasActivities,
            'generated' => $generated,
            'staffing_synced' => $staffingSynced,
            'locked' => $locked,
            'generate_skipped' => $generateSkipped,
        ];
    }

    /**
     * @return array{id: int, existing: bool, locked: bool, last_change?: mixed}
     */
    private function ensurePlanRow(int $eventId): array
    {
        $response = app(PlanController::class)->getOrCreatePlanForEvent($eventId);
        /** @var array{id: int, existing: bool, locked?: bool, last_change?: mixed} $data */
        $data = $response->getData(true);

        return [
            'id' => (int) $data['id'],
            'existing' => (bool) ($data['existing'] ?? false),
            'locked' => (bool) ($data['locked'] ?? false),
            'last_change' => $data['last_change'] ?? null,
        ];
    }

    private function afterGenerate(int $eventId, int $planId): void
    {
        try {
            app(EventAttentionService::class)->updateEventAttentionStatus($eventId);
            app(CalendarFeedService::class)->rebuildSafely($eventId);
            if (config('staffing.sync_after_generate')) {
                try {
                    $this->staffing->syncForEvent($eventId);
                } catch (\Throwable $staffingError) {
                    Log::warning('Staffing sync after ensure-generate failed', [
                        'plan_id' => $planId,
                        'error' => $staffingError->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Post-generate hooks after ensure failed', [
                'plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
