<?php

declare(strict_types=1);

namespace App\Print;

use App\Support\TableFieldLabels;

final class RoleSheetCells
{
    public const VOLUNTEER = 'Freiwilliges Team ohne Wertung';

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
            return self::hotLabel(
                self::stringOrNull($activity['team_name'] ?? $activity['jury_team_name'] ?? null),
                $activity['jury_team_number_hot'] ?? $activity['team_number_hot'] ?? null,
            );
        }

        if ($roleParam === 'team' && self::isMatch($activity)) {
            $opp = self::opponentSide($activity, $selfTeam, $selfTable);

            return self::plainTeamName($opp['name'], $opp['number']);
        }

        if ($roleName === 'Schiedsrichter:in' && $roleParam === 'table') {
            $own = self::ownSide($activity, $selfTeam, $selfTable);
            $opp = self::opponentSide($activity, $selfTeam, $selfTable);

            return self::pair(
                self::sideHotOrVolunteer($own),
                self::sideHotOrVolunteer($opp),
            );
        }

        if ($roleName === 'Robot-Checker:in') {
            return self::sideHotOrVolunteer(self::ownSide($activity, $selfTeam, $selfTable));
        }

        if ($roleName === 'Betreuer:in Allianz-Gespräche') {
            $own = self::ownSide($activity, $selfTeam, $selfTable);
            $opp = self::opponentSide($activity, $selfTeam, $selfTable);

            return self::pair(
                self::sideHotOrVolunteer($own),
                self::sideHotOrVolunteer($opp),
            );
        }

        if ($roleName === 'Moderator:in' && self::isMatch($activity)) {
            $left = self::plainTeamName(
                self::stringOrNull($activity['table_1_team_name'] ?? null),
                self::intOrNull($activity['table_1_team'] ?? null),
            );
            $right = self::plainTeamName(
                self::stringOrNull($activity['table_2_team_name'] ?? null),
                self::intOrNull($activity['table_2_team'] ?? null),
            );

            return self::pair($left, $right);
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
            $candidates[] = trim((string) ($activity['team_name'] ?? $activity['jury_team_name'] ?? ''));
        }
        if (self::isTruthy($activity['table_1_team_noshow'] ?? false)) {
            $candidates[] = trim((string) ($activity['table_1_team_name'] ?? ''));
        }
        if (self::isTruthy($activity['table_2_team_noshow'] ?? false)) {
            $candidates[] = trim((string) ($activity['table_2_team_name'] ?? ''));
        }

        $strike = [];
        foreach ($candidates as $name) {
            if ($name !== '' && str_contains($text, $name) && ! in_array($name, $strike, true)) {
                $strike[] = $name;
            }
        }

        return $strike;
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

    /**
     * @param  array{name: ?string, hot: mixed, number: ?int}  $side
     */
    private static function sideHotOrVolunteer(array $side): string
    {
        if (self::isVolunteer($side['name'], $side['number'])) {
            return self::VOLUNTEER;
        }

        return self::hotLabel($side['name'], $side['hot']);
    }

    private static function plainTeamName(?string $name, ?int $number): string
    {
        if (self::isVolunteer($name, $number)) {
            return self::VOLUNTEER;
        }

        return (string) $name;
    }

    private static function isVolunteer(?string $name, ?int $number): bool
    {
        if ($number === 0) {
            return true;
        }

        return trim((string) $name) === '';
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
