<?php

namespace App\Support;

class GermanMobileNumber
{
    /**
     * @return array{ok: true, normalized: string|null}|array{ok: false, error: string}
     */
    public static function validateAndNormalize(?string $raw): array
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '' || self::isEmptyPlaceholder($trimmed)) {
            return ['ok' => true, 'normalized' => null];
        }

        if (! preg_match('/^[0-9+\s\-\/().]+$/', $trimmed)) {
            return ['ok' => false, 'error' => 'Ungültige Mobilnummer'];
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';
        $national = null;

        if (str_starts_with($trimmed, '+')) {
            if (! str_starts_with($trimmed, '+49')) {
                return ['ok' => false, 'error' => 'Nur deutsche Nummern (+49)'];
            }
            $national = substr($digits, 2);
        } elseif (str_starts_with($digits, '0049')) {
            $national = substr($digits, 4);
        } elseif (str_starts_with($digits, '49') && strlen($digits) >= 12) {
            $national = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
        } else {
            return ['ok' => false, 'error' => 'Ungültige Mobilnummer'];
        }

        if ($national === '' || strlen($national) < 10 || strlen($national) > 12) {
            return ['ok' => false, 'error' => 'Ungültige Mobilnummer'];
        }

        return [
            'ok' => true,
            'normalized' => self::formatGermanInternational($national),
        ];
    }

    private static function formatGermanInternational(string $national): string
    {
        if (strlen($national) >= 10) {
            return '+49 '.substr($national, 0, 3).' '.substr($national, 3);
        }

        return '+49 '.$national;
    }

    private static function isEmptyPlaceholder(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return true;
        }

        if (in_array($normalized, ['-', '--', '---', '...', '…', 'n/a', 'na', 'k.a.', 'k. a.', 'none', 'null'], true)) {
            return true;
        }

        return (bool) preg_match('/^[-–—.…]+$/u', $normalized);
    }
}
