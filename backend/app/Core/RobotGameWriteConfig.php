<?php

namespace App\Core;

/**
 * Activity codes and parameter names RobotGameGenerator uses when writing a round.
 * Challenge is the default; Future 8+ passes its own keys.
 */
final class RobotGameWriteConfig
{
    /**
     * @param array<int, string> $roundGroupCodes round number => activity_type_detail code
     */
    public function __construct(
        public readonly array $roundGroupCodes,
        public readonly string $matchCode,
        public readonly ?string $checkCode,
        public readonly ?string $robotCheckParam,
        public readonly string $durationTestMatch,
        public readonly string $durationMatch,
        public readonly string $durationNextStart,
        public readonly string $durationBreak,
        public readonly string $durationLunch,
        public readonly string $tablesParam,
        public readonly ?string $lunchBreakEarlyParam,
        public readonly ?string $hardLunchDurationParam,
        public readonly ?string $durationTransfer = null,
        public readonly string $durationCheck = 'r_duration_robot_check',
    ) {
    }

    /**
     * Pause after a game round. Future floors the referee break to transfer min
     * so a team last in this round and first in the next still meets Q1.
     * Challenge passes null transfer and keeps the catalog break as-is.
     */
    public static function gameRoundBreakMinutes(int $breakMinutes, ?int $transferMinutes): int
    {
        if ($transferMinutes === null) {
            return $breakMinutes;
        }

        return max($breakMinutes, $transferMinutes);
    }

    public static function challenge(): self
    {
        return new self(
            roundGroupCodes: [
                0 => 'r_test_round',
                1 => 'r_round_1',
                2 => 'r_round_2',
                3 => 'r_round_3',
            ],
            matchCode: 'r_match',
            checkCode: 'r_check',
            robotCheckParam: 'r_robot_check',
            durationTestMatch: 'r_duration_test_match',
            durationMatch: 'r_duration_match',
            durationNextStart: 'r_duration_next_start',
            durationBreak: 'r_duration_break',
            durationLunch: 'r_duration_lunch',
            tablesParam: 'r_tables',
            lunchBreakEarlyParam: 'c_lunch_break_early',
            hardLunchDurationParam: 'c_duration_lunch_break',
            durationTransfer: null,
            durationCheck: 'r_duration_robot_check',
        );
    }

    public static function future8(): self
    {
        return new self(
            roundGroupCodes: [
                0 => 'f8_test_round',
                1 => 'f8_round_1',
                2 => 'f8_round_2',
                3 => 'f8_round_3',
                4 => 'f8_round_4',
                5 => 'f8_round_5',
            ],
            matchCode: 'f8_r_match',
            checkCode: 'f8_r_alliance',
            robotCheckParam: 'f8_r_alliance_meeting',
            durationTestMatch: 'f8_r_duration_test_match',
            durationMatch: 'f8_r_duration_match',
            durationNextStart: 'f8_r_duration_next_start',
            durationBreak: 'f8_r_duration_break',
            durationLunch: 'f8_r_duration_lunch',
            tablesParam: 'f8_fields',
            lunchBreakEarlyParam: null,
            hardLunchDurationParam: 'f8_duration_lunch_break',
            durationTransfer: 'f8_duration_transfer',
            durationCheck: 'f8_r_duration_alliance_meeting',
        );
    }
}
