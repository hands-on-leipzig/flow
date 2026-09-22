<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FirstProgram;

/**
 * Default nouns and uniqueness for Robot Game table / mat display names.
 *
 * Challenge and unknown → "Tisch N". Future 8+ → Matte rot/blau maps from table count.
 * Stored values are full free-text overrides; empty means use the default.
 */
final class TableFieldLabels
{
    public const MAX_LENGTH = 100;

    /** @var array<int, string> */
    private const FUTURE_LABELS_2 = [
        1 => 'Matte rot',
        2 => 'Matte blau',
    ];

    /** @var array<int, string> */
    private const FUTURE_LABELS_4 = [
        1 => 'Matte rot 1',
        2 => 'Matte blau 1',
        3 => 'Matte rot 2',
        4 => 'Matte blau 2',
    ];

    public static function supports(int $firstProgramId): bool
    {
        return $firstProgramId === FirstProgram::CHALLENGE->value
            || $firstProgramId === FirstProgram::FUTURE_8->value;
    }

    public static function noun(int $firstProgramId): string
    {
        return $firstProgramId === FirstProgram::FUTURE_8->value ? 'Matte' : 'Tisch';
    }

    public static function plural(int $firstProgramId): string
    {
        return $firstProgramId === FirstProgram::FUTURE_8->value ? 'Matten' : 'Tische';
    }

    public static function abbrev(int $firstProgramId): string
    {
        return $firstProgramId === FirstProgram::FUTURE_8->value ? 'M' : 'T';
    }

    public static function pluralSlash(): string
    {
        return 'Tische/Matten';
    }

    public static function defaultLabel(int $firstProgramId, int $tableNumber, int $tableCount = 0): string
    {
        if ($firstProgramId !== FirstProgram::FUTURE_8->value) {
            return 'Tisch '.$tableNumber;
        }

        $map = self::futureMap($tableNumber, $tableCount);

        return $map[$tableNumber] ?? ('Matte '.$tableNumber);
    }

    public static function juryPlaceHeader(int $firstProgramId): string
    {
        return 'Jury/'.self::noun($firstProgramId);
    }

    /**
     * Strip a leading default noun ("Tisch "/"Matte ") so compact PDFs can show the rest.
     * Custom names that do not start with the program noun are unchanged.
     */
    public static function stripLeadingNoun(int $firstProgramId, string $label): string
    {
        $noun = self::noun($firstProgramId);
        $stripped = preg_replace('/^'.preg_quote($noun, '/').'\\s+/u', '', $label);
        if (! is_string($stripped) || $stripped === '') {
            return $label;
        }

        return $stripped;
    }

    /**
     * Full display label: trimmed custom if non-empty, else program default.
     */
    public static function effective(int $firstProgramId, int $tableNumber, ?string $custom, int $tableCount = 0): string
    {
        $trimmed = trim((string) $custom);
        if ($trimmed !== '') {
            return $trimmed;
        }

        return self::defaultLabel($firstProgramId, $tableNumber, $tableCount);
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
            $label = self::effective($firstProgramId, $n, $customsByNumber[$n] ?? null, $count);
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
     * @return array<int, string>
     */
    private static function futureMap(int $tableNumber, int $tableCount): array
    {
        if ($tableCount === 4) {
            return self::FUTURE_LABELS_4;
        }

        return $tableNumber >= 3 ? self::FUTURE_LABELS_4 : self::FUTURE_LABELS_2;
    }
}
