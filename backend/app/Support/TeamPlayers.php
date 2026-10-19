<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Team members (Teammitglieder) as delivered by DRAHT /handson/flow/{event}/people.
 * DRAHT stores birthdays as Unix timestamps in its own timezone; FLOW works with Y-m-d.
 */
final class TeamPlayers
{
    public const GENDERS = ['', 'M', 'F', 'D'];

    private const DRAHT_TIMEZONE = 'Europe/Berlin';

    /**
     * @param  mixed  $players  DRAHT players list
     * @return list<array{firstname: string, name: string, gender: string, birthday: ?string}>
     */
    public static function serializeList(mixed $players): array
    {
        if (! is_array($players)) {
            return [];
        }

        $result = [];
        foreach ($players as $player) {
            if (! is_array($player)) {
                continue;
            }
            $result[] = [
                'firstname' => (string) ($player['firstname'] ?? ''),
                'name' => (string) ($player['name'] ?? ''),
                'gender' => strtoupper((string) ($player['gender'] ?? '')),
                'birthday' => self::birthdayYmd($player['birthday'] ?? null),
            ];
        }

        return $result;
    }

    public static function birthdayYmd(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_numeric($value)) {
            $ts = (int) $value;

            return $ts > 0 ? Carbon::createFromTimestamp($ts, self::DRAHT_TIMEZONE)->format('Y-m-d') : null;
        }
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $value, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    /**
     * @return array{minAge: int, maxAge: int, referenceDate: string}|null
     */
    public static function ageRule(mixed $rule): ?array
    {
        if (! is_array($rule)) {
            return null;
        }
        $reference = self::birthdayYmd($rule['referenceDate'] ?? null);
        if (! is_numeric($rule['minAge'] ?? null) || ! is_numeric($rule['maxAge'] ?? null) || $reference === null) {
            return null;
        }

        return [
            'minAge' => (int) $rule['minAge'],
            'maxAge' => (int) $rule['maxAge'],
            'referenceDate' => $reference,
        ];
    }

    public static function ageOn(string $birthdayYmd, string $referenceYmd): int
    {
        [$by, $bm, $bd] = array_map('intval', explode('-', $birthdayYmd));
        [$ry, $rm, $rd] = array_map('intval', explode('-', $referenceYmd));
        $age = $ry - $by;
        if ($rm < $bm || ($rm === $bm && $rd < $bd)) {
            $age--;
        }

        return $age;
    }

    /**
     * Age on the reference date when outside the rule; null when allowed or not checkable.
     *
     * @param  array{minAge: int, maxAge: int, referenceDate: string}|null  $rule
     */
    public static function ageOutOfRange(?string $birthdayYmd, ?array $rule): ?int
    {
        if ($rule === null || $birthdayYmd === null) {
            return null;
        }
        $age = self::ageOn($birthdayYmd, $rule['referenceDate']);

        return ($age < $rule['minAge'] || $age > $rule['maxAge']) ? $age : null;
    }

    /**
     * @param  list<array{birthday: ?string}>  $players  Serialized players
     * @param  array{minAge: int, maxAge: int, referenceDate: string}|null  $rule
     */
    public static function countAgeOutOfRange(array $players, ?array $rule): int
    {
        $count = 0;
        foreach ($players as $player) {
            if (self::ageOutOfRange($player['birthday'] ?? null, $rule) !== null) {
                $count++;
            }
        }

        return $count;
    }
}
