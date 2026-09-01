<?php

namespace App\Support;

use App\Enums\ExploreMode;

/**
 * Ceremony-time parameter visibility (Ablauf → Zeiten start editability).
 * Used by CeremonyTimesService.
 */
final class TimeColumnVisibility
{
    /** @var list<string> */
    private const FIELDS = [
        'c_start_opening', 'c_duration_opening', 'c_duration_awards',
        'f8_start_opening', 'f8_duration_opening', 'f8_duration_awards',
        'g_start_opening', 'g_duration_opening', 'g_duration_awards',
        'e1_start_opening', 'e1_duration_opening', 'e1_duration_awards',
        'e2_start_opening', 'e2_duration_opening', 'e2_duration_awards',
    ];

    /** @var list<int> */
    private const EXPLORE_INTEGRATED_OR_OFF = [
        ExploreMode::NONE->value,
        ExploreMode::INTEGRATED_MORNING->value,
        ExploreMode::INTEGRATED_AFTERNOON->value,
    ];

    /**
     * @return array<string, array{e_mode: int, c_mode: int, f8_mode: int, fields: array<string, array{editable: bool}>, columns: list<string>}>
     */
    public static function matrix(): array
    {
        $matrix = [];

        for ($e = 0; $e <= 8; $e++) {
            for ($c = 0; $c <= 1; $c++) {
                for ($f8 = 0; $f8 <= 1; $f8++) {
                    $entry = array_fill_keys(self::FIELDS, ['editable' => false]);
                    $invalidLead = in_array($e, self::EXPLORE_INTEGRATED_OR_OFF, true) && $c === 0 && $f8 === 0;

                    if (! $invalidLead) {
                        if ($c === 1) {
                            self::enableLeadTimes($entry, $e, 'c');
                        }
                        if ($f8 === 1) {
                            self::enableLeadTimes($entry, $e, 'f8');
                        }
                        if ($c === 1 && $f8 === 1) {
                            self::forceJointAwards($entry);
                        }
                        self::enableExploreTimes($entry, $e);
                    }

                    $payload = [
                        'e_mode' => $e,
                        'c_mode' => $c,
                        'f8_mode' => $f8,
                        'fields' => $entry,
                        'columns' => self::timeColumns($entry),
                    ];

                    $matrix["e{$e}_c{$c}_f8{$f8}"] = $payload;
                    if ($f8 === 0) {
                        $matrix["e{$e}_c{$c}"] = $payload;
                    }
                }
            }
        }

        return $matrix;
    }

    /**
     * @return list<string>
     */
    public static function editableOpeningParams(int $eMode, int $cMode, int $f8Mode): array
    {
        $fields = self::fieldsForModes($eMode, $cMode, $f8Mode);
        $openings = [];

        foreach (self::FIELDS as $field) {
            if (! str_ends_with($field, '_start_opening')) {
                continue;
            }
            if ($fields[$field]['editable'] ?? false) {
                $openings[] = $field;
            }
        }

        return $openings;
    }

    public static function prefixForParam(string $paramName): ?string
    {
        if (! str_ends_with($paramName, '_start_opening')) {
            return null;
        }

        $prefix = substr($paramName, 0, -strlen('_start_opening'));

        return in_array($prefix, ['g', 'e1', 'e2', 'c', 'f8'], true) ? $prefix : null;
    }

    /**
     * @return array<string, array{editable: bool}>
     */
    public static function fieldsForModes(int $eMode, int $cMode, int $f8Mode): array
    {
        $key = "e{$eMode}_c{$cMode}_f8{$f8Mode}";
        $matrix = self::matrix();

        return $matrix[$key]['fields'] ?? array_fill_keys(self::FIELDS, ['editable' => false]);
    }

    /**
     * @param  array<string, array{editable: bool}>  $entry
     */
    private static function enableLeadTimes(array &$entry, int $e, string $prefix): void
    {
        $own = [
            "{$prefix}_start_opening",
            "{$prefix}_duration_opening",
            "{$prefix}_duration_awards",
        ];

        switch ($e) {
            case ExploreMode::NONE->value:
            case ExploreMode::DECOUPLED_MORNING->value:
            case ExploreMode::DECOUPLED_AFTERNOON->value:
            case ExploreMode::DECOUPLED_BOTH->value:
                foreach ($own as $field) {
                    $entry[$field]['editable'] = true;
                }
                break;

            case ExploreMode::INTEGRATED_MORNING->value:
                foreach (['g_start_opening', 'g_duration_opening', "{$prefix}_duration_awards", 'e1_duration_awards'] as $field) {
                    $entry[$field]['editable'] = true;
                }
                break;

            case ExploreMode::INTEGRATED_AFTERNOON->value:
                foreach (["{$prefix}_start_opening", "{$prefix}_duration_opening", 'g_duration_awards', 'e2_duration_opening'] as $field) {
                    $entry[$field]['editable'] = true;
                }
                break;

            case ExploreMode::HYBRID_BOTH->value:
                foreach (['g_start_opening', 'g_duration_opening', 'e1_duration_awards', 'e2_duration_opening', 'g_duration_awards'] as $field) {
                    $entry[$field]['editable'] = true;
                }
                break;
        }
    }

    /**
     * @param  array<string, array{editable: bool}>  $entry
     */
    private static function forceJointAwards(array &$entry): void
    {
        $entry['c_duration_awards']['editable'] = false;
        $entry['f8_duration_awards']['editable'] = false;
        $entry['g_duration_awards']['editable'] = true;
    }

    /**
     * @param  array<string, array{editable: bool}>  $entry
     */
    private static function enableExploreTimes(array &$entry, int $e): void
    {
        switch ($e) {
            case ExploreMode::DECOUPLED_MORNING->value:
                foreach (['e1_start_opening', 'e1_duration_opening', 'e1_duration_awards'] as $field) {
                    $entry[$field]['editable'] = true;
                }
                break;

            case ExploreMode::DECOUPLED_AFTERNOON->value:
                foreach (['e2_start_opening', 'e2_duration_opening', 'e2_duration_awards'] as $field) {
                    $entry[$field]['editable'] = true;
                }
                break;

            case ExploreMode::DECOUPLED_BOTH->value:
                foreach ([
                    'e1_start_opening', 'e1_duration_opening', 'e1_duration_awards',
                    'e2_start_opening', 'e2_duration_opening', 'e2_duration_awards',
                ] as $field) {
                    $entry[$field]['editable'] = true;
                }
                break;
        }
    }

    /**
     * @param  array<string, array{editable: bool}>  $entry
     * @return list<string>
     */
    private static function timeColumns(array $entry): array
    {
        $columns = [];
        foreach (['g', 'e1', 'e2', 'c', 'f8'] as $prefix) {
            if (
                ($entry["{$prefix}_start_opening"]['editable'] ?? false)
                || ($entry["{$prefix}_duration_opening"]['editable'] ?? false)
                || ($entry["{$prefix}_duration_awards"]['editable'] ?? false)
            ) {
                $columns[] = $prefix;
            }
        }

        return $columns;
    }
}
