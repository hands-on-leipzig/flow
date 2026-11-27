<?php

namespace App\Scor;

/**
 * Season rules for the Future referee sheet. The form may show the rows;
 * this module decides which combinations are legal and how they score.
 */
final class FutureRefereeRules
{
    public const YOUNG_FOREST = 'Schlüsselarten im jungen Wald';

    public const QUEEN_BONUS = 'Bonus invasive Königin';

    public const RETREAT = 'Schlüsselarten oder Ressourcen vollständig im Rückzugsort';

    public const PENALTY = 'Beeinträchtigungen in diesem Match';

    public const GRACIOUS_SECTION = 'Freundliches Miteinander';

    private const QUEEN_BONUS_FOREST = 3;

    /**
     * @param  iterable<object>  $items
     * @param  array{values: array<string, mixed>}  $answers
     */
    public static function assertLegal(iterable $items, array $answers): void
    {
        $bonus = self::raw($items, self::QUEEN_BONUS, $answers);
        if ($bonus === 'yes' && self::intValue($items, self::YOUNG_FOREST, $answers) < self::QUEEN_BONUS_FOREST) {
            throw new ScorAccessException('Der Bonus invasive Königin gilt erst ab 3 Schlüsselarten im jungen Wald.', 422);
        }
    }

    /**
     * Game points. Freundliches Miteinander stays out: it counts toward Grundwerte, not the match.
     *
     * @param  iterable<object>  $items
     * @param  array{values: array<string, mixed>}  $answers
     */
    public static function finalizePoints(int $points, iterable $items, array $answers): int
    {
        if (self::zerosMatch($items, $answers)) {
            return 0;
        }

        return $points - self::graciousPoints($items, $answers);
    }

    /**
     * @param  iterable<object>  $items
     * @param  array{values: array<string, mixed>}  $answers
     */
    public static function graciousPoints(iterable $items, array $answers): int
    {
        $item = self::graciousItem($items);
        $raw = $item ? ($answers['values'][(string) $item->id] ?? null) : null;
        if (!is_numeric($raw)) {
            return 0;
        }
        $option = self::config($item)['options'][(int) $raw] ?? null;

        return is_array($option) ? (int) ($option['points'] ?? 0) : 0;
    }

    /**
     * Rows that only open once another row reaches a minimum.
     *
     * @param  iterable<object>  $items
     * @return array<int, array{item: int, min: int}>
     */
    public static function requirements(iterable $items): array
    {
        $bonus = self::find($items, self::QUEEN_BONUS);
        $forest = self::find($items, self::YOUNG_FOREST);
        if (!$bonus || !$forest) {
            return [];
        }

        return [(int) $bonus->id => ['item' => (int) $forest->id, 'min' => self::QUEEN_BONUS_FOREST]];
    }

    /**
     * @param  iterable<object>  $items
     * @param  array{values: array<string, mixed>}  $answers
     */
    public static function retreatItem(iterable $items): ?object
    {
        return self::find($items, self::RETREAT);
    }

    /**
     * @param  iterable<object>  $items
     */
    public static function graciousItem(iterable $items): ?object
    {
        foreach ($items as $item) {
            if ($item->item_type === 'choice' && trim((string) ($item->section_title ?? '')) === self::GRACIOUS_SECTION) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  iterable<object>  $items
     * @param  array{values: array<string, mixed>}  $answers
     */
    private static function intValue(iterable $items, string $label, array $answers): int
    {
        $raw = self::raw($items, $label, $answers);

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * @param  iterable<object>  $items
     * @param  array{values: array<string, mixed>}  $answers
     */
    private static function raw(iterable $items, string $label, array $answers): mixed
    {
        $item = self::find($items, $label);
        if (!$item) {
            return null;
        }

        return $answers['values'][(string) $item->id] ?? null;
    }

    /**
     * @param  iterable<object>  $items
     * @param  array{values: array<string, mixed>}  $answers
     */
    private static function zerosMatch(iterable $items, array $answers): bool
    {
        $item = self::find($items, self::PENALTY);
        $raw = $item ? ($answers['values'][(string) $item->id] ?? null) : null;
        if ($item === null || !is_numeric($raw)) {
            return false;
        }
        $option = self::config($item)['options'][(int) $raw] ?? null;
        if (!is_array($option)) {
            return false;
        }
        if (!empty($option['zeros_match'])) {
            return true;
        }

        return in_array((string) ($option['label'] ?? ''), ['Dritte', '3 oder mehr'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(object $item): array
    {
        if (is_array($item->config ?? null)) {
            return $item->config;
        }
        $config = json_decode((string) ($item->config ?? ''), true);

        return is_array($config) ? $config : [];
    }

    /**
     * @param  iterable<object>  $items
     */
    private static function find(iterable $items, string $label): ?object
    {
        foreach ($items as $item) {
            if ((string) $item->label === $label) {
                return $item;
            }
        }

        return null;
    }
}
