<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FirstProgram;
use InvalidArgumentException;

/**
 * Default nouns and uniqueness for Robot Game table / field display names.
 *
 * Challenge → "Tisch N", Future 8+ → "Spielfeld N".
 * Stored values are full free-text overrides; empty means use the default.
 */
final class TableFieldLabels
{
    public const MAX_LENGTH = 100;

    public static function supports(int $firstProgramId): bool
    {
        return $firstProgramId === FirstProgram::CHALLENGE->value
            || $firstProgramId === FirstProgram::FUTURE_8->value;
    }

    public static function noun(int $firstProgramId): string
    {
        return match ($firstProgramId) {
            FirstProgram::CHALLENGE->value => 'Tisch',
            FirstProgram::FUTURE_8->value => 'Spielfeld',
            default => throw new InvalidArgumentException(
                "TableFieldLabels: program {$firstProgramId} has no table/field labels."
            ),
        };
    }

    public static function defaultLabel(int $firstProgramId, int $tableNumber): string
    {
        return self::noun($firstProgramId).' '.$tableNumber;
    }

    /**
     * Full display label: trimmed custom if non-empty, else program default.
     */
    public static function effective(int $firstProgramId, int $tableNumber, ?string $custom): string
    {
        $trimmed = trim((string) $custom);
        if ($trimmed !== '') {
            return $trimmed;
        }

        return self::defaultLabel($firstProgramId, $tableNumber);
    }

    /**
     * Param name for table/field count on the plan (r_tables / f8_fields).
     */
    public static function countParamName(int $firstProgramId): string
    {
        return ChallengeShapedParamMap::from($firstProgramId)->tables();
    }

    /**
     * @param  array<int, string|null>  $customsByNumber  table_number => custom name (may be empty)
     * @return list<string>  duplicate effective labels (normalized), empty if unique
     */
    public static function duplicateEffectiveLabels(int $firstProgramId, int $count, array $customsByNumber): array
    {
        if ($count < 1) {
            return [];
        }

        $seen = [];
        $dupes = [];

        for ($n = 1; $n <= $count; $n++) {
            $label = self::effective($firstProgramId, $n, $customsByNumber[$n] ?? null);
            $key = mb_strtolower($label);
            if (isset($seen[$key])) {
                $dupes[$key] = $label;
            } else {
                $seen[$key] = true;
            }
        }

        return array_values($dupes);
    }

    /**
     * SQL expression fragment for the default noun from atd.first_program / fp.
     * Challenge and unknown → Tisch; Future 8+ → Spielfeld.
     */
    public static function sqlDefaultNounExpression(string $firstProgramColumn = 'atd.first_program'): string
    {
        $f8 = FirstProgram::FUTURE_8->value;

        return "CASE WHEN {$firstProgramColumn} = {$f8} THEN \"Spielfeld\" ELSE \"Tisch\" END";
    }
}
