<?php

namespace App\Support;

use App\Core\ChallengeGenerator;
use App\Core\Future8Generator;
use App\Enums\FirstProgram;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Which programs are on a plan/event, and which Challenge-shaped program leads.
 *
 * Presence: event_program. On: attached + mode on (+ teams > 0 for Challenge-shaped).
 * Lead: lowest m_first_program.sequence among Challenge-shaped programs that are on.
 */
class ProgramPresence
{
    /**
     * Challenge-shaped programs that can own morning clocks / games.
     *
     * @var array<int, array{mode: string, teams: string, lanes: string, tables: string, generator: class-string}>
     */
    public const CHALLENGE_SHAPED = [
        FirstProgram::CHALLENGE->value => [
            'mode' => 'c_mode',
            'teams' => 'c_teams',
            'lanes' => 'j_lanes',
            'tables' => 'r_tables',
            'generator' => ChallengeGenerator::class,
        ],
        FirstProgram::FUTURE_8->value => [
            'mode' => 'f8_mode',
            'teams' => 'f8_teams',
            'lanes' => 'f8_lanes',
            'tables' => 'f8_fields',
            'generator' => Future8Generator::class,
        ],
    ];

    /** @var list<int> */
    private array $attachedIds;

    public function __construct(
        private readonly int $planId,
        private readonly PlanParameter $params,
    ) {
        $eventId = (int) (DB::table('plan')->where('id', $planId)->value('event') ?? 0);
        $this->attachedIds = self::attachedProgramIds($eventId);
    }

    public static function forPlan(int $planId, PlanParameter $params): self
    {
        return new self($planId, $params);
    }

    /** @return list<int> */
    public static function attachedProgramIds(int $eventId): array
    {
        if ($eventId < 1) {
            return [];
        }

        return DB::table('event_program')
            ->where('event', $eventId)
            ->pluck('first_program')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public static function attachedProgramIdsForPlan(int $planId): array
    {
        $eventId = (int) (DB::table('plan')->where('id', $planId)->value('event') ?? 0);

        return self::attachedProgramIds($eventId);
    }

    /**
     * Delete plan_param_value rows for catalog programs not on the plan's event.
     */
    public static function purgeParametersOutsideEvent(int $planId): int
    {
        $eventId = (int) (DB::table('plan')->where('id', $planId)->value('event') ?? 0);
        if ($eventId < 1) {
            return 0;
        }

        $programIds = self::attachedProgramIds($eventId);

        $query = DB::table('plan_param_value')
            ->where('plan', $planId)
            ->whereIn('parameter', function ($sub) use ($programIds) {
                $sub->select('id')
                    ->from('m_parameter')
                    ->whereNotNull('first_program')
                    ->where('first_program', '>', 0);

                if ($programIds !== []) {
                    $sub->whereNotIn('first_program', $programIds);
                }
            });

        $deleted = $query->delete();

        if ($deleted > 0) {
            Log::info('Purged plan parameters for programs not on the event', [
                'plan_id' => $planId,
                'deleted' => $deleted,
            ]);
        }

        return $deleted;
    }

    /** @return list<int> */
    public function attachedIds(): array
    {
        return $this->attachedIds;
    }

    public function isAttached(int $firstProgramId): bool
    {
        return in_array($firstProgramId, $this->attachedIds, true);
    }

    public function exploreOn(): bool
    {
        if (! $this->isAttached(FirstProgram::EXPLORE->value)
            && ! $this->isAttached(FirstProgram::DISCOVER->value)) {
            return false;
        }

        return (int) $this->params->get('e_mode', 0) > 0;
    }

    public function exploreMode(): int
    {
        return $this->exploreOn() ? (int) $this->params->get('e_mode', 0) : 0;
    }

    public function challengeShapedOn(int $firstProgramId): bool
    {
        $def = self::CHALLENGE_SHAPED[$firstProgramId] ?? null;
        if ($def === null || ! $this->isAttached($firstProgramId)) {
            return false;
        }

        return (int) $this->params->get($def['mode'], 0) === 1
            && (int) $this->params->get($def['teams'], 0) > 0;
    }

    /** @return list<int> */
    public function challengeShapedOnIds(): array
    {
        $ids = [];
        foreach (array_keys(self::CHALLENGE_SHAPED) as $programId) {
            if ($this->challengeShapedOn($programId)) {
                $ids[] = $programId;
            }
        }

        if ($ids === []) {
            return [];
        }

        $sequence = DB::table('m_first_program')
            ->whereIn('id', $ids)
            ->pluck('sequence', 'id');

        usort($ids, function (int $a, int $b) use ($sequence) {
            $sa = (int) ($sequence[$a] ?? 999);
            $sb = (int) ($sequence[$b] ?? 999);
            if ($sa === $sb) {
                return $a <=> $b;
            }

            return $sa <=> $sb;
        });

        return $ids;
    }

    public function leadProgramId(): ?int
    {
        $on = $this->challengeShapedOnIds();

        return $on[0] ?? null;
    }

    /** @return list<int> */
    public function skippedLeadProgramIds(): array
    {
        $on = $this->challengeShapedOnIds();
        if (count($on) <= 1) {
            return [];
        }

        return array_values(array_slice($on, 1));
    }

    public function leadGeneratorClass(): ?string
    {
        $leadId = $this->leadProgramId();
        if ($leadId === null) {
            return null;
        }

        return self::CHALLENGE_SHAPED[$leadId]['generator'] ?? null;
    }

    public function programOn(int $firstProgramId): bool
    {
        if ($firstProgramId === FirstProgram::EXPLORE->value
            || $firstProgramId === FirstProgram::DISCOVER->value) {
            return $this->exploreOn();
        }

        if (isset(self::CHALLENGE_SHAPED[$firstProgramId])) {
            return $this->challengeShapedOn($firstProgramId);
        }

        if ($firstProgramId === FirstProgram::JOINT->value) {
            return true;
        }

        return $this->isAttached($firstProgramId);
    }
}
