<?php

namespace App\Support;

use Illuminate\Http\Request;

final class SpreadsheetExportVariant
{
    public const FULL = 'full';

    public const EMAIL = 'email';

    public static function parse(Request $request, string $default = self::FULL): string
    {
        $variant = (string) $request->query('variant', $default);

        return in_array($variant, [self::FULL, self::EMAIL], true) ? $variant : $default;
    }
}
