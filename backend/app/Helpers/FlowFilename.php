<?php

namespace App\Helpers;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * Shared download naming: FLOW_{Name}_({dd.mm.yy}).{ext}
 */
class FlowFilename
{
    /**
     * @param  string  $name  Descriptor (and optional qualifier), e.g. Plan_mit_WLAN, People_Teams_Explore
     * @param  DateTimeInterface|string|null  $date  Calendar/datetime, already-formatted d.m.y, or null = now (Berlin)
     */
    public static function make(string $name, string $extension, DateTimeInterface|string|null $date = null): string
    {
        $safeName = self::sanitizeName($name);
        $formattedDate = self::formatDate($date);
        $ext = ltrim($extension, '.');

        return "FLOW_{$safeName}_({$formattedDate}).{$ext}";
    }

    public static function formatDate(DateTimeInterface|string|null $date = null): string
    {
        if (is_string($date) && preg_match('/^\d{2}\.\d{2}\.\d{2}$/', $date)) {
            return $date;
        }

        if ($date === null || $date === '') {
            return now('Europe/Berlin')->format('d.m.y');
        }

        return Carbon::parse($date)->timezone('Europe/Berlin')->format('d.m.y');
    }

    public static function sanitizeName(string $name): string
    {
        $name = str_replace(
            ['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'],
            ['ae', 'oe', 'ue', 'Ae', 'Oe', 'Ue', 'ss'],
            $name
        );
        $name = preg_replace('/\s+/', '_', $name) ?? $name;
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name) ?? $name;

        return $name;
    }
}
