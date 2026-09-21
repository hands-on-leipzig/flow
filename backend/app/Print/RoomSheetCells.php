<?php

declare(strict_types=1);

namespace App\Print;

final class RoomSheetCells
{
    /** @var list<string> */
    private const MATCH_OR_ALLIANCE_CODES = ['r_match', 'f8_r_match', 'f8_r_alliance'];

    /** @var list<string> */
    private const JUDGING_WITH_TEAM_CODES = ['j_with_team', 'e_with_team', 'f8_j_with_team', 'lc_with_team'];

    /** @var list<string> */
    private const SLOT_CODES = ['e_slot_block', 'c_slot_block', 'f8_slot_block', 'g_slot_block'];

    private const ROBOT_CHECK_CODE = 'r_check';

    /**
     * @param  array<string, mixed>  $activity
     * @return array{text: string, strike: list<string>}
     */
    public static function action(array $activity): array
    {
        $atd = self::activityName($activity);
        $parts = [];
        $teams = self::teamsExtra($activity);
        if ($teams !== '') {
            $parts[] = $teams;
        }
        $tables = self::tablesExtra($activity);
        if ($tables !== '') {
            $parts[] = $tables;
        }
        $text = self::combine($atd, implode(', ', $parts));

        return [
            'text' => $text,
            'strike' => self::strikeNames($activity, $text),
        ];
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private static function activityName(array $activity): string
    {
        $atd = trim((string) ($activity['activity_atd_name'] ?? ''));
        if ($atd !== '') {
            return $atd;
        }

        return trim((string) ($activity['activity_name'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private static function teamsExtra(array $activity): string
    {
        $code = self::code($activity);
        if (in_array($code, self::MATCH_OR_ALLIANCE_CODES, true)) {
            return RoleSheetCells::pair(
                self::tableTeamLabel($activity, 1),
                self::tableTeamLabel($activity, 2),
            );
        }
        if (in_array($code, self::JUDGING_WITH_TEAM_CODES, true)) {
            return RoleSheetCells::teamLabel(
                self::stringOrNull($activity['jury_team_name'] ?? $activity['team_name'] ?? null),
                $activity['jury_team'] ?? $activity['team'] ?? null,
                $activity['jury_team_number_hot'] ?? $activity['team_number_hot'] ?? null,
                true,
            );
        }
        if ($code === self::ROBOT_CHECK_CODE) {
            $has1 = self::sideSet($activity, 1);
            $has2 = self::sideSet($activity, 2);
            if ($has1 && $has2) {
                return RoleSheetCells::pair(
                    self::tableTeamLabel($activity, 1),
                    self::tableTeamLabel($activity, 2),
                );
            }
            if ($has1) {
                return self::tableTeamLabel($activity, 1);
            }
            if ($has2) {
                return self::tableTeamLabel($activity, 2);
            }

            return '';
        }
        if (in_array($code, self::SLOT_CODES, true)) {
            return RoleSheetCells::teamLabel(
                self::stringOrNull($activity['slot_team_name'] ?? null),
                $activity['slot_team'] ?? null,
                $activity['slot_team_number_hot'] ?? null,
                true,
            );
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private static function tablesExtra(array $activity): string
    {
        $code = self::code($activity);
        if (! in_array($code, [...self::MATCH_OR_ALLIANCE_CODES, self::ROBOT_CHECK_CODE], true)) {
            return '';
        }
        $names = [];
        foreach (['table_1_name', 'table_2_name'] as $key) {
            $name = trim((string) ($activity[$key] ?? ''));
            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return implode(', ', $names);
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private static function tableTeamLabel(array $activity, int $which): string
    {
        $prefix = $which === 2 ? 'table_2' : 'table_1';

        return RoleSheetCells::teamLabel(
            self::stringOrNull($activity[$prefix.'_team_name'] ?? null),
            $activity[$prefix.'_team'] ?? null,
            $activity[$prefix.'_team_number_hot'] ?? null,
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private static function sideSet(array $activity, int $which): bool
    {
        $prefix = $which === 2 ? 'table_2' : 'table_1';
        $name = trim((string) ($activity[$prefix.'_team_name'] ?? ''));
        if ($name !== '') {
            return true;
        }
        $number = $activity[$prefix.'_team'] ?? null;

        return $number !== null && $number !== '';
    }

    private static function combine(string $name, string $extra): string
    {
        $name = trim($name);
        $extra = trim($extra);
        if ($name === '') {
            return $extra;
        }
        if ($extra === '') {
            return $name;
        }

        return $name.', '.$extra;
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return list<string>
     */
    private static function strikeNames(array $activity, string $text): array
    {
        $candidates = [];
        if (self::isTruthy($activity['jury_team_noshow'] ?? false)) {
            $candidates[] = RoleSheetCells::teamLabel(
                self::stringOrNull($activity['jury_team_name'] ?? $activity['team_name'] ?? null),
                $activity['jury_team'] ?? $activity['team'] ?? null,
                $activity['jury_team_number_hot'] ?? $activity['team_number_hot'] ?? null,
                true,
            );
        }
        if (self::isTruthy($activity['table_1_team_noshow'] ?? false)) {
            $candidates[] = self::tableTeamLabel($activity, 1);
        }
        if (self::isTruthy($activity['table_2_team_noshow'] ?? false)) {
            $candidates[] = self::tableTeamLabel($activity, 2);
        }
        if (self::isTruthy($activity['slot_team_noshow'] ?? false)) {
            $candidates[] = RoleSheetCells::teamLabel(
                self::stringOrNull($activity['slot_team_name'] ?? null),
                $activity['slot_team'] ?? null,
                $activity['slot_team_number_hot'] ?? null,
                true,
            );
        }

        $strike = [];
        foreach ($candidates as $name) {
            if ($name !== '' && $name !== RoleSheetCells::VOLUNTEER && str_contains($text, $name) && ! in_array($name, $strike, true)) {
                $strike[] = $name;
            }
        }

        usort($strike, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $kept = [];
        foreach ($strike as $name) {
            foreach ($kept as $longer) {
                if (str_contains($longer, $name)) {
                    continue 2;
                }
            }
            $kept[] = $name;
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private static function code(array $activity): string
    {
        return (string) ($activity['activity_type_code'] ?? '');
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function isTruthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
