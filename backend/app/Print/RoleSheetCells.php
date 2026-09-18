<?php

declare(strict_types=1);

namespace App\Print;

use App\Support\TableFieldLabels;

final class RoleSheetCells
{
    public const VOLUNTEER = 'Freiwilliges Team ohne Wertung';

    public const UNASSIGNED = 'Noch nicht angemeldet';

    /** @var list<string> */
    private const MATCH_CODES = ['r_match', 'f8_r_match'];

    /** @var list<string> */
    private const JUDGING_WITH_TEAM_CODES = ['j_with_team', 'e_with_team', 'f8_j_with_team', 'lc_with_team'];

    private const ROBOT_CHECK_CODE = 'r_check';

    private const ALLIANCE_CODE = 'f8_r_alliance';

    public static function hotLabel(?string $name, mixed $hot): string
    {
        $name = trim((string) $name);
        if ($hot === null || $hot === '') {
            return $name;
        }

        return sprintf('%s (%04d)', $name, (int) $hot);
    }

    public static function pair(string $left, string $right): string
    {
        return $left.' – '.$right;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    public static function isMatch(array $activity): bool
    {
        return in_array(self::code($activity), self::MATCH_CODES, true);
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    public static function isRobotCheck(array $activity): bool
    {
        return self::code($activity) === self::ROBOT_CHECK_CODE;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    public static function isAlliance(array $activity): bool
    {
        return self::code($activity) === self::ALLIANCE_CODE;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    public static function isJudgingWithTeam(array $activity): bool
    {
        return in_array(self::code($activity), self::JUDGING_WITH_TEAM_CODES, true);
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    public static function room(array $activity, string $roleParam, ?int $selfTeam, ?int $selfTable, ?int $programId): string
    {
        $physical = trim((string) (
            $activity['room']['room_name']
            ?? $activity['room_name']
            ?? ''
        ));

        $suffix = '';
        if ($roleParam === 'team' && self::isMatch($activity) && $selfTeam !== null) {
            $ownTable = self::ownTableNumber($activity, $selfTeam, $selfTable);
            $program = $programId
                ?? self::intOrNull($activity['meta']['first_program_id'] ?? null)
                ?? self::intOrNull($activity['activity_first_program_id'] ?? null);
            if ($ownTable !== null && $program !== null && $program > 0) {
                $suffix = TableFieldLabels::effective(
                    $program,
                    $ownTable,
                    self::tableStoredName($activity, $ownTable),
                );
            }
        }

        if ($physical === '' && $suffix === '') {
            return '';
        }
        if ($physical === '') {
            return $suffix;
        }
        if ($suffix === '') {
            return $physical;
        }

        return $physical.', '.$suffix;
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return array{text: string, strike: list<string>}
     */
    public static function action(
        array $activity,
        string $roleName,
        string $roleParam,
        ?int $selfTeam,
        ?int $selfTable,
    ): array {
        $extra = self::actionExtra($activity, $roleName, $roleParam, $selfTeam, $selfTable);
        $text = self::combineAction(self::activityName($activity), $extra);

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
        $name = trim((string) ($activity['activity_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return trim((string) ($activity['meta']['name'] ?? ''));
    }

    private static function combineAction(string $name, string $extra): string
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
     */
    private static function actionExtra(
        array $activity,
        string $roleName,
        string $roleParam,
        ?int $selfTeam,
        ?int $selfTable,
    ): string {
        if ($roleParam === 'lane' && self::isJudgingWithTeam($activity)) {
            return self::teamLabel(
                self::stringOrNull($activity['team_name'] ?? $activity['jury_team_name'] ?? null),
                $activity['team'] ?? $activity['jury_team'] ?? null,
                $activity['jury_team_number_hot'] ?? $activity['team_number_hot'] ?? null,
                true,
            );
        }

        if ($roleParam === 'team' && self::isMatch($activity)) {
            $opp = self::opponentSide($activity, $selfTeam, $selfTable);

            return self::teamLabel($opp['name'], $opp['number']);
        }

        if ($roleName === 'Schiedsrichter:in' && $roleParam === 'table') {
            $own = self::ownSide($activity, $selfTeam, $selfTable);
            $opp = self::opponentSide($activity, $selfTeam, $selfTable);

            return self::pair(
                self::teamLabel($own['name'], $own['number'], $own['hot'], true),
                self::teamLabel($opp['name'], $opp['number'], $opp['hot'], true),
            );
        }

        if ($roleName === 'Robot-Checker:in') {
            $own = self::ownSide($activity, $selfTeam, $selfTable);

            return self::teamLabel($own['name'], $own['number'], $own['hot'], true);
        }

        if ($roleName === 'Betreuer:in Allianz-Gespräche') {
            $own = self::ownSide($activity, $selfTeam, $selfTable);
            $opp = self::opponentSide($activity, $selfTeam, $selfTable);

            return self::pair(
                self::teamLabel($own['name'], $own['number'], $own['hot'], true),
                self::teamLabel($opp['name'], $opp['number'], $opp['hot'], true),
            );
        }

        if ($roleName === 'Moderator:in' && self::isMatch($activity)) {
            return self::pair(
                self::teamLabel(
                    self::stringOrNull($activity['table_1_team_name'] ?? null),
                    $activity['table_1_team'] ?? null,
                ),
                self::teamLabel(
                    self::stringOrNull($activity['table_2_team_name'] ?? null),
                    $activity['table_2_team'] ?? null,
                ),
            );
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return list<string>
     */
    private static function strikeNames(array $activity, string $text): array
    {
        $candidates = [];
        if (self::isTruthy($activity['jury_team_noshow'] ?? false)) {
            $candidates[] = self::teamLabel(
                self::stringOrNull($activity['team_name'] ?? $activity['jury_team_name'] ?? null),
                $activity['team'] ?? $activity['jury_team'] ?? null,
                $activity['jury_team_number_hot'] ?? $activity['team_number_hot'] ?? null,
                true,
            );
        }
        if (self::isTruthy($activity['table_1_team_noshow'] ?? false)) {
            $candidates[] = self::teamLabel(
                self::stringOrNull($activity['table_1_team_name'] ?? null),
                $activity['table_1_team'] ?? null,
                $activity['table_1_team_number_hot'] ?? null,
                true,
            );
            $candidates[] = self::teamLabel(
                self::stringOrNull($activity['table_1_team_name'] ?? null),
                $activity['table_1_team'] ?? null,
            );
        }
        if (self::isTruthy($activity['table_2_team_noshow'] ?? false)) {
            $candidates[] = self::teamLabel(
                self::stringOrNull($activity['table_2_team_name'] ?? null),
                $activity['table_2_team'] ?? null,
                $activity['table_2_team_number_hot'] ?? null,
                true,
            );
            $candidates[] = self::teamLabel(
                self::stringOrNull($activity['table_2_team_name'] ?? null),
                $activity['table_2_team'] ?? null,
            );
        }

        $strike = [];
        foreach ($candidates as $name) {
            if ($name !== '' && $name !== self::VOLUNTEER && str_contains($text, $name) && ! in_array($name, $strike, true)) {
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
     * @return array{name: ?string, hot: mixed, number: ?int}
     */
    private static function ownSide(array $activity, ?int $selfTeam, ?int $selfTable): array
    {
        $which = self::ownWhich($activity, $selfTeam, $selfTable);
        if ($which === 2) {
            return [
                'name' => self::stringOrNull($activity['table_2_team_name'] ?? null),
                'hot' => $activity['table_2_team_number_hot'] ?? null,
                'number' => self::intOrNull($activity['table_2_team'] ?? null),
            ];
        }

        return [
            'name' => self::stringOrNull($activity['table_1_team_name'] ?? null),
            'hot' => $activity['table_1_team_number_hot'] ?? null,
            'number' => self::intOrNull($activity['table_1_team'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return array{name: ?string, hot: mixed, number: ?int}
     */
    private static function opponentSide(array $activity, ?int $selfTeam, ?int $selfTable): array
    {
        $which = self::ownWhich($activity, $selfTeam, $selfTable);
        if ($which === 2) {
            return [
                'name' => self::stringOrNull($activity['table_1_team_name'] ?? null),
                'hot' => $activity['table_1_team_number_hot'] ?? null,
                'number' => self::intOrNull($activity['table_1_team'] ?? null),
            ];
        }

        return [
            'name' => self::stringOrNull($activity['table_2_team_name'] ?? null),
            'hot' => $activity['table_2_team_number_hot'] ?? null,
            'number' => self::intOrNull($activity['table_2_team'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private static function ownWhich(array $activity, ?int $selfTeam, ?int $selfTable): int
    {
        if ($selfTable !== null) {
            if (self::intOrNull($activity['table_1'] ?? null) === $selfTable) {
                return 1;
            }
            if (self::intOrNull($activity['table_2'] ?? null) === $selfTable) {
                return 2;
            }
        }
        if ($selfTeam !== null) {
            if (self::intOrNull($activity['table_1_team'] ?? null) === $selfTeam) {
                return 1;
            }
            if (self::intOrNull($activity['table_2_team'] ?? null) === $selfTeam) {
                return 2;
            }
        }

        return 1;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private static function ownTableNumber(array $activity, ?int $selfTeam, ?int $selfTable): ?int
    {
        if ($selfTeam !== null) {
            if (self::intOrNull($activity['table_1_team'] ?? null) === $selfTeam) {
                return self::intOrNull($activity['table_1'] ?? null);
            }
            if (self::intOrNull($activity['table_2_team'] ?? null) === $selfTeam) {
                return self::intOrNull($activity['table_2'] ?? null);
            }
        }

        return $selfTable;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private static function tableStoredName(array $activity, int $tableNumber): ?string
    {
        if (self::intOrNull($activity['table_1'] ?? null) === $tableNumber) {
            return self::stringOrNull($activity['table_1_name'] ?? null);
        }
        if (self::intOrNull($activity['table_2'] ?? null) === $tableNumber) {
            return self::stringOrNull($activity['table_2_name'] ?? null);
        }

        return null;
    }

    public static function teamLabel(?string $name, mixed $number, mixed $hot = null, bool $withHot = false): string
    {
        $slot = self::intOrNull($number);
        if ($slot === 0) {
            return self::VOLUNTEER;
        }

        $name = trim((string) $name);
        if ($name === '') {
            if ($slot === null || $slot < 1) {
                return '';
            }

            return sprintf('T%02d (%s)', $slot, self::UNASSIGNED);
        }

        return $withHot ? self::hotLabel($name, $hot) : $name;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private static function code(array $activity): string
    {
        return (string) ($activity['activity_type_code'] ?? '');
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
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
