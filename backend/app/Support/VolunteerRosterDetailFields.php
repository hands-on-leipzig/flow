<?php

namespace App\Support;

use App\Models\EventVolunteerRosterDetail;

class VolunteerRosterDetailFields
{
    public const T_SHIRT_CUTS = ['maenner', 'frauen'];

    public const T_SHIRT_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];

    public const T_SHIRT_CUT_LABELS = [
        'maenner' => 'Männer',
        'frauen' => 'Frauen',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $allowedMeals
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string}
     */
    public static function validate(array $input, array $allowedMeals): array
    {
        $cut = self::nullableString($input, 't_shirt_cut');
        $size = self::nullableString($input, 't_shirt_size');
        $meal = self::nullableString($input, 'meal');
        $photoConsent = self::nullableBoolean($input, 'photo_consent');

        if ($cut !== null && ! in_array($cut, self::T_SHIRT_CUTS, true)) {
            return ['ok' => false, 'error' => 'Ungültiger T-Shirt-Schnitt.'];
        }
        if ($size !== null && ! in_array($size, self::T_SHIRT_SIZES, true)) {
            return ['ok' => false, 'error' => 'Ungültige T-Shirt-Größe.'];
        }
        if (($cut === null) xor ($size === null)) {
            return ['ok' => false, 'error' => 'Bitte Schnitt und Größe gemeinsam angeben oder leer lassen.'];
        }

        if ($meal !== null && ! in_array($meal, $allowedMeals, true)) {
            return ['ok' => false, 'error' => 'Ungültige Essenswahl.'];
        }

        return [
            'ok' => true,
            'data' => [
                't_shirt_cut' => $cut,
                't_shirt_size' => $size,
                'meal' => $meal,
                'photo_consent' => $photoConsent,
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
                'photo_consent' => null,
                'updated_at' => null,
            ];
        }

        return [
            't_shirt_cut' => $detail->t_shirt_cut,
            't_shirt_size' => $detail->t_shirt_size,
            'meal' => $detail->meal,
            'photo_consent' => $detail->photo_consent === null ? null : (bool) $detail->photo_consent,
            'updated_at' => optional($detail->updated_at)?->toIso8601String(),
        ];
    }

    public static function exportLabel(?string $cut): string
    {
        return $cut ? (self::T_SHIRT_CUT_LABELS[$cut] ?? $cut) : '';
    }

    /**
     * @param  array<string, string>  $labelMap
     */
    public static function exportMealLabel(?string $meal, array $labelMap = []): string
    {
        if ($meal === null || $meal === '') {
            return '';
        }

        return $labelMap[$meal] ?? $meal;
    }

    public static function exportPhotoConsentLabel(?bool $consent): string
    {
        if ($consent === null) {
            return '';
        }

        return $consent ? 'ja' : 'nein';
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

    /**
     * @param  array<string, mixed>  $input
     */
    private static function nullableBoolean(array $input, string $key): ?bool
    {
        if (! array_key_exists($key, $input)) {
            return null;
        }

        $value = $input[$key];
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [0, '0', 'false', false], true)) {
            return false;
        }

        if (in_array($value, [1, '1', 'true', true], true)) {
            return true;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed;
    }
}
