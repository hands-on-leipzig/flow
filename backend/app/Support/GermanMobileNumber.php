<?php

namespace App\Support;

class GermanMobileNumber
{
    /**
     * Assigned E.164 country calling codes. Not area codes: 1 is NANP, 7 is Russia and Kazakhstan.
     * Keep this list identical to frontend/src/utils/mobileNumber.ts.
     *
     * @var list<string>
     */
    private const CALLING_CODES = [
        '1',
        '20', '211', '212', '213', '216', '218',
        '220', '221', '222', '223', '224', '225', '226', '227', '228', '229',
        '230', '231', '232', '233', '234', '235', '236', '237', '238', '239',
        '240', '241', '242', '243', '244', '245', '246', '247', '248', '249',
        '250', '251', '252', '253', '254', '255', '256', '257', '258',
        '260', '261', '262', '263', '264', '265', '266', '267', '268', '269',
        '27', '290', '291', '297', '298', '299',
        '30', '31', '32', '33', '34',
        '350', '351', '352', '353', '354', '355', '356', '357', '358', '359',
        '36',
        '370', '371', '372', '373', '374', '375', '376', '377', '378', '379',
        '380', '381', '382', '383', '385', '386', '387', '389',
        '39', '40', '41', '420', '421', '423',
        '43', '44', '45', '46', '47', '48', '49',
        '500', '501', '502', '503', '504', '505', '506', '507', '508', '509',
        '51', '52', '53', '54', '55', '56', '57', '58',
        '590', '591', '592', '593', '594', '595', '596', '597', '598', '599',
        '60', '61', '62', '63', '64', '65', '66',
        '670', '672', '673', '674', '675', '676', '677', '678', '679',
        '680', '681', '682', '683', '685', '686', '687', '688', '689',
        '690', '691', '692',
        '7',
        '81', '82', '84', '850', '852', '853', '855', '856', '86', '880', '886',
        '90', '91', '92', '93', '94', '95',
        '960', '961', '962', '963', '964', '965', '966', '967', '968',
        '970', '971', '972', '973', '974', '975', '976', '977',
        '98', '992', '993', '994', '995', '996', '998',
    ];

    /**
     * German national numbers keep the German format. Other countries need + or 00 and an assigned calling code.
     *
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

        if (str_starts_with($trimmed, '+') || str_starts_with($digits, '00')) {
            $international = str_starts_with($trimmed, '+') ? $digits : substr($digits, 2);

            return self::internationalFromDigits($international);
        }

        if (str_starts_with($digits, '49') && strlen($digits) >= 12) {
            return self::germanFromNational(substr($digits, 2));
        }

        if (str_starts_with($digits, '0')) {
            return self::germanFromNational(substr($digits, 1));
        }

        return ['ok' => false, 'error' => 'Ungültige Mobilnummer'];
    }

    /**
     * @return array{ok: true, normalized: string}|array{ok: false, error: string}
     */
    private static function germanFromNational(string $national): array
    {
        if ($national === '' || strlen($national) < 10 || strlen($national) > 12) {
            return ['ok' => false, 'error' => 'Ungültige Mobilnummer'];
        }

        return [
            'ok' => true,
            'normalized' => self::formatGermanInternational($national),
        ];
    }

    /**
     * @return array{ok: true, normalized: string}|array{ok: false, error: string}
     */
    private static function internationalFromDigits(string $digits): array
    {
        $code = self::matchCallingCode($digits);
        if ($code === null) {
            return ['ok' => false, 'error' => 'Ungültige Mobilnummer'];
        }

        $national = substr($digits, strlen($code));
        if ($code === '49') {
            return self::germanFromNational($national);
        }

        if (str_starts_with($national, '0')) {
            $national = substr($national, 1);
        }

        if ($national === '') {
            return ['ok' => false, 'error' => 'Ungültige Mobilnummer'];
        }

        $total = strlen($code) + strlen($national);
        if ($total < 8 || $total > 15) {
            return ['ok' => false, 'error' => 'Ungültige Mobilnummer'];
        }

        return [
            'ok' => true,
            'normalized' => '+'.$code.' '.$national,
        ];
    }

    private static function matchCallingCode(string $digits): ?string
    {
        foreach ([3, 2, 1] as $length) {
            if (strlen($digits) < $length) {
                continue;
            }

            $prefix = substr($digits, 0, $length);
            if (in_array($prefix, self::CALLING_CODES, true)) {
                return $prefix;
            }
        }

        return null;
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
