<?php

namespace App\Support;

use App\Enums\FirstProgram;

/**
 * How many lane / table / team slices a catalog role has on a plan.
 * Replaces m_role.differentiation_source SQL.
 */
final class RoleDifferentiation
{
    public static function optionCount(?int $firstProgram, ?string $parameter, PlanParameter $params): int
    {
        if ($firstProgram === null || $parameter === null || $parameter === '') {
            return 0;
        }

        return match ($parameter) {
            'lane' => self::laneCount($firstProgram, $params),
            'table' => self::tableCount($firstProgram, $params),
            'team' => self::teamCount($firstProgram, $params),
            default => 0,
        };
    }

    private static function laneCount(int $programId, PlanParameter $params): int
    {
        return match ($programId) {
            FirstProgram::EXPLORE->value => max(0, (int) $params->get('e1_lanes', 0))
                + max(0, (int) $params->get('e2_lanes', 0)),
            FirstProgram::CHALLENGE->value => max(0, (int) $params->get('j_lanes', 0)),
            FirstProgram::FUTURE_8->value => max(0, (int) $params->get('f8_lanes', 0)),
            default => 0,
        };
    }

    private static function tableCount(int $programId, PlanParameter $params): int
    {
        return match ($programId) {
            FirstProgram::CHALLENGE->value => max(0, (int) $params->get('r_tables', 0)),
            FirstProgram::FUTURE_8->value => max(0, (int) $params->get('f8_fields', 0)),
            default => 0,
        };
    }

    private static function teamCount(int $programId, PlanParameter $params): int
    {
        return match ($programId) {
            FirstProgram::EXPLORE->value => max(0, (int) $params->get('e_teams', 0)),
            FirstProgram::CHALLENGE->value => max(0, (int) $params->get('c_teams', 0)),
            FirstProgram::FUTURE_8->value => max(0, (int) $params->get('f8_teams', 0)),
            default => 0,
        };
    }
}
