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

    /**
     * @param  iterable<object>  $items
     * @param  array{values: array<string, mixed>}  $answers
     */
    public static function assertLegal(iterable $items, array $answers): void
    {
        $bonus = self::raw($items, self::QUEEN_BONUS, $answers);
        if ($bonus === 'yes' && self::intValue($items, self::YOUNG_FOREST, $answers) < 3) {
            throw new ScorAccessException('Der Bonus invasive Königin gilt erst ab 3 Schlüsselarten im jungen Wald.', 422);
        }
    }

    /**
     * @param  iterable<object>  $items
     * @param  array{values: array<string, mixed>}  $answers
     */
    public static function finalizePoints(int $points, iterable $items, array $answers): int
    {
        if (self::choiceLabel($items, self::PENALTY, $answers) === 'Dritte') {
            return 0;
        }

        return $points;
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
    private static function choiceLabel(iterable $items, string $label, array $answers): ?string
    {
        $item = self::find($items, $label);
        $raw = $item ? ($answers['values'][(string) $item->id] ?? null) : null;
        if ($item === null || !is_numeric($raw)) {
            return null;
        }
        $config = json_decode((string) ($item->config ?? ''), true);
        if (!is_array($config)) {
            $config = is_array($item->config ?? null) ? $item->config : [];
        }
        $option = $config['options'][(int) $raw] ?? null;

        return is_array($option) ? (string) ($option['label'] ?? '') : null;
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
