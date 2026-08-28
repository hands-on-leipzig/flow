<?php

namespace App\Support;

use App\Models\EventVolunteerRosterDetail;

class VolunteerRosterDetailFields
{
    public const T_SHIRT_CUTS = ['maenner', 'frauen'];

    public const T_SHIRT_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];

    public const MEALS = ['standard', 'vegetarisch', 'vegan', 'keine'];

    public const T_SHIRT_CUT_LABELS = [
        'maenner' => 'Männer',
        'frauen' => 'Frauen',
    ];

    public const MEAL_LABELS = [
        'standard' => 'Standard',
        'vegetarisch' => 'Vegetarisch',
        'vegan' => 'Vegan',
        'keine' => 'Keine',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string}
     */
    public static function validate(array $input): array
    {
        $cut = self::nullableString($input, 't_shirt_cut');
        $size = self::nullableString($input, 't_shirt_size');
        $meal = self::nullableString($input, 'meal');
        $notes = array_key_exists('notes', $input)
            ? ($input['notes'] === null || $input['notes'] === '' ? null : trim((string) $input['notes']))
            : null;

        if ($cut !== null && ! in_array($cut, self::T_SHIRT_CUTS, true)) {
            return ['ok' => false, 'error' => 'Ungültiger T-Shirt-Schnitt.'];
        }
        if ($size !== null && ! in_array($size, self::T_SHIRT_SIZES, true)) {
            return ['ok' => false, 'error' => 'Ungültige T-Shirt-Größe.'];
        }
        if (($cut === null) xor ($size === null)) {
            return ['ok' => false, 'error' => 'Bitte Schnitt und Größe gemeinsam angeben oder leer lassen.'];
        }
        if ($meal !== null && ! in_array($meal, self::MEALS, true)) {
            return ['ok' => false, 'error' => 'Ungültige Essenswahl.'];
        }
        if ($notes !== null && mb_strlen($notes) > 1000) {
            return ['ok' => false, 'error' => 'Bemerkungen dürfen maximal 1000 Zeichen haben.'];
        }

        return [
            'ok' => true,
            'data' => [
                't_shirt_cut' => $cut,
                't_shirt_size' => $size,
                'meal' => $meal,
                'notes' => $notes,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serialize(?EventVolunteerRosterDetail $detail): array
    {
        if (! $detail) {
            return [
                't_shirt_cut' => null,
                't_shirt_size' => null,
                'meal' => null,
                'notes' => null,
                'updated_at' => null,
            ];
        }

        return [
            't_shirt_cut' => $detail->t_shirt_cut,
            't_shirt_size' => $detail->t_shirt_size,
            'meal' => $detail->meal,
            'notes' => $detail->notes,
            'updated_at' => optional($detail->updated_at)?->toIso8601String(),
        ];
    }

    public static function exportLabel(?string $cut): string
    {
        return $cut ? (self::T_SHIRT_CUT_LABELS[$cut] ?? $cut) : '';
    }

    public static function exportMealLabel(?string $meal): string
    {
        return $meal ? (self::MEAL_LABELS[$meal] ?? $meal) : '';
    }

    private static function nullableString(array $input, string $key): ?string
    {
        if (! array_key_exists($key, $input)) {
            return null;
        }
        $value = $input[$key];
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }
}
