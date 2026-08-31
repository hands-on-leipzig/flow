<?php

namespace App\Services;

use App\Http\Controllers\Api\PlanController;
use App\Models\Event;
use App\Models\Plan;
use App\Models\Team;
use App\Models\TeamPlan;
use App\Support\ProgramCatalog;
use App\Support\TeamSyncMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TeamSyncService
{
    public function __construct(
        private readonly EventAttentionService $attentionService,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $drahtTeamsRaw
     * @return array{applied: array{removed: int, added: int, updated: int}, teams: mixed}
     */
    public function sync(Event $event, string $programParam, array $drahtTeamsRaw): array
    {
        $program = ProgramCatalog::resolve($programParam);
        if (! $program) {
            throw new \InvalidArgumentException('Program not found');
        }

        $plan = Plan::where('event', $event->id)->first();
        if (! $plan) {
            throw new \RuntimeException('No plan found for this event');
        }

        $localTeams = $this->loadLocalTeams($event, $program->id, $plan->id);
        $drahtTeams = TeamSyncMatcher::normalizeDrahtTeams($drahtTeamsRaw);
        $merged = TeamSyncMatcher::merge($localTeams, $drahtTeams);
        $counts = TeamSyncMatcher::actionCounts($merged);

        try {
            DB::transaction(function () use ($event, $program, $plan, $merged) {
                foreach ($merged as $row) {
                    if ($row['status'] === 'missing' && ! empty($row['local']['id'])) {
                        Team::where('id', $row['local']['id'])->delete();
                    }
                }

                foreach ($merged as $row) {
                    if ($row['status'] === 'conflict' && $row['local'] && $row['draht']) {
                        Team::where('id', $row['local']['id'])->update([
                            'name' => $row['draht']['name'],
                        ]);
                    }
                }

                foreach ($merged as $row) {
                    if ($row['status'] !== 'new' || ! $row['draht']) {
                        continue;
                    }
                    $draht = $row['draht'];
                    $number = $draht['number'] ?? TeamSyncMatcher::normalizeTeamNumber($row['number'] ?? null);
                    if ($number === null) {
                        continue;
                    }

                    $team = new Team();
                    $team->first_program = $program->id;
                    $team->name = $draht['name'];
                    $team->event = $event->id;
                    $team->team_number_hot = $number;
                    $team->location = $draht['location'] ?? null;
                    $team->organization = $draht['organization'] ?? null;
                    $team->save();
                }

                app(PlanController::class)->syncTeamPlanForEvent($event->id);
                $this->renumberTeamPlanForProgram($plan->id, $event->id, $program->id);
            });
        } catch (Throwable $e) {
            Log::error('Team sync failed', [
                'event_id' => $event->id,
                'program' => $programParam,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->attentionService->updateEventAttentionStatus($event->id);

        return [
            'applied' => $counts,
            'teams' => $this->teamsIndexPayload($event, $program, $plan),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadLocalTeams(Event $event, int $programId, int $planId): array
    {
        return Team::query()
            ->where('event', $event->id)
            ->where('first_program', $programId)
            ->leftJoin('team_plan', function ($join) use ($planId) {
                $join->on('team.id', '=', 'team_plan.team')
                    ->where('team_plan.plan', '=', $planId);
            })
            ->orderBy('team_plan.team_number_plan')
            ->get([
                'team.id',
                'team.name',
                'team.team_number_hot',
                'team.location',
                'team.organization',
                'team_plan.team_number_plan',
                'team_plan.noshow',
            ])
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'team_number_hot' => $t->team_number_hot,
                'location' => $t->location,
                'organization' => $t->organization,
                'team_number_plan' => $t->team_number_plan,
                'noshow' => $t->noshow,
            ])
            ->all();
    }

    private function teamsIndexPayload(Event $event, $program, Plan $plan): mixed
    {
        $teams = Team::query()
            ->where('event', $event->id)
            ->where('first_program', $program->id)
            ->leftJoin('team_plan', function ($join) use ($plan) {
                $join->on('team.id', '=', 'team_plan.team')
                    ->where('team_plan.plan', '=', $plan->id);
            })
            ->select('team.*', 'team_plan.team_number_plan', 'team_plan.room', 'team_plan.noshow')
            ->orderBy('team_plan.team_number_plan')
            ->get();

        if (strcasecmp((string) $program->name, 'EXPLORE') === 0) {
            $e1Teams = DB::table('plan_param_value')
                ->join('m_parameter', 'plan_param_value.parameter', '=', 'm_parameter.id')
                ->where('plan_param_value.plan', $plan->id)
                ->where('m_parameter.name', 'e1_teams')
                ->value('plan_param_value.set_value');

            $eMode = DB::table('plan_param_value')
                ->join('m_parameter', 'plan_param_value.parameter', '=', 'm_parameter.id')
                ->where('plan_param_value.plan', $plan->id)
                ->where('m_parameter.name', 'e_mode')
                ->value('plan_param_value.set_value');

            return [
                'teams' => $teams,
                'metadata' => [
                    'e1_teams' => $e1Teams ? (int) $e1Teams : 0,
                    'e_mode' => $eMode ? (int) $eMode : 0,
                ],
            ];
        }

        return $teams;
    }

    private function renumberTeamPlanForProgram(int $planId, int $eventId, int $programId): void
    {
        $teams = Team::where('event', $eventId)
            ->where('first_program', $programId)
            ->get();

        if ($teams->isEmpty()) {
            return;
        }

        $teamPlanEntries = TeamPlan::where('plan', $planId)
            ->whereIn('team', $teams->pluck('id'))
            ->orderBy('team_number_plan')
            ->get();

        foreach ($teamPlanEntries as $index => $entry) {
            TeamPlan::where('team', $entry->team)
                ->where('plan', $planId)
                ->update(['team_number_plan' => $index + 1]);
        }
    }
}
