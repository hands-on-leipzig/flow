<?php

namespace App\Support;

use App\Enums\FirstProgram;

/**
 * How many lane / table / table_pair / team slices a catalog role has on a plan.
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
            'table_pair' => self::tablePairCount($firstProgram, $params),
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

    private static function tablePairCount(int $programId, PlanParameter $params): int
    {
        if ($programId !== FirstProgram::FUTURE_8->value) {
            return 0;
        }

        $tables = self::tableCount($programId, $params);
        if ($tables < 1) {
            return 0;
        }

        return (int) ceil($tables / 2);
    }

    /**
     * Concurrent-match column for an alliance/match activity (tables 1+2 → 1, 3+4 → 2).
     * Empty 0+0 is null. A volunteer side (0) uses the real table as the anchor.
     */
    public static function tablePairIndex(int $table1, int $table2): ?int
    {
        $low = min($table1, $table2);
        $high = max($table1, $table2);
        if ($high < 1) {
            return null;
        }

        $anchor = $low > 0 ? $low : $high;

        return (int) ceil($anchor / 2);
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
