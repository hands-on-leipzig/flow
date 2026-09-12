<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FirstProgram;

/**
 * Activity-group codes for Challenge-shaped quality evaluation.
 *
 * Hard gates require the core morning groups (judging + TR + RG1–3).
 * Fetch also includes Future catalog rounds 4 and 5 when those groups exist.
 * Challenge finals stay excluded.
 */
final class QualityTeamActivityGroups
{
    /**
     * Groups that must exist for a plan to be evaluable.
     *
     * @return list<string>
     */
    public static function required(int $firstProgram): array
    {
        if ($firstProgram === FirstProgram::FUTURE_8->value) {
            return [
                'f8_j_package',
                'f8_test_round',
                'f8_round_1',
                'f8_round_2',
                'f8_round_3',
            ];
        }

        return [
            'j_package',
            'r_test_round',
            'r_round_1',
            'r_round_2',
            'r_round_3',
        ];
    }

    /**
     * Groups whose team activities feed Q1/Q6.
     *
     * @return list<string>
     */
    public static function fetch(int $firstProgram): array
    {
        $required = self::required($firstProgram);
        if ($firstProgram === FirstProgram::FUTURE_8->value) {
            return [...$required, 'f8_round_4', 'f8_round_5'];
        }

        return $required;
    }
}
