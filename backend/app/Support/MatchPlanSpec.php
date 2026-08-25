<?php

namespace App\Support;

use App\Enums\FirstProgram;
use InvalidArgumentException;

/**
 * Challenge-shaped match-plan inputs bound to catalog / derived param names.
 *
 * Inputs (teams, lanes, tables) and generator-derived values (rounds, matches
 * per round, volunteer, asym) are read via PlanParameter — never recalculated here.
 */
final class MatchPlanSpec
{
    /**
     * @var array<int, array{
     *     teams: string,
     *     lanes: string,
     *     tables: string,
     *     judgingRounds: string,
     *     matchesPerRound: string,
     *     needVolunteer: string,
     *     asym: string
     * }>
     */
    private const KEYS = [
        FirstProgram::CHALLENGE->value => [
            'teams' => 'c_teams',
            'lanes' => 'j_lanes',
            'tables' => 'r_tables',
            'judgingRounds' => 'j_rounds',
            'matchesPerRound' => 'r_matches_per_round',
            'needVolunteer' => 'r_need_volunteer',
            'asym' => 'r_asym',
        ],
        FirstProgram::FUTURE_8->value => [
            'teams' => 'f8_teams',
            'lanes' => 'f8_lanes',
            'tables' => 'f8_fields',
            'judgingRounds' => 'f8_j_rounds',
            'matchesPerRound' => 'f8_r_matches_per_round',
            'needVolunteer' => 'f8_r_need_volunteer',
            'asym' => 'f8_r_asym',
        ],
    ];

    public function __construct(
        public readonly FirstProgram $program,
        public readonly int $planId,
        public readonly int $teams,
        public readonly int $lanes,
        public readonly int $tables,
        public readonly int $judgingRounds,
        public readonly int $matchesPerRound,
        public readonly bool $needVolunteer,
        public readonly bool $asym,
        public readonly bool $finale,
    ) {
    }

    public static function for(FirstProgram $program, PlanParameter $params): self
    {
        $keys = self::KEYS[$program->value] ?? null;
        if ($keys === null) {
            throw new InvalidArgumentException(
                'MatchPlanSpec: program '.$program->value.' is not Challenge-shaped.'
            );
        }

        return new self(
            program: $program,
            planId: (int) $params->get('g_plan'),
            teams: (int) $params->get($keys['teams']),
            lanes: (int) $params->get($keys['lanes']),
            tables: (int) $params->get($keys['tables']),
            judgingRounds: (int) $params->get($keys['judgingRounds']),
            matchesPerRound: (int) $params->get($keys['matchesPerRound']),
            needVolunteer: (bool) $params->get($keys['needVolunteer']),
            asym: (bool) $params->get($keys['asym']),
            finale: (bool) $params->get('g_finale', false),
        );
    }
}
