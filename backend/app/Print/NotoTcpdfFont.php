<?php

declare(strict_types=1);

namespace App\Print;

/**
 * Noto Sans for TCPDF without changing K_PATH_FONTS.
 *
 * Converted files live next to the source TTFs so they can be committed;
 * TCPDF is pointed at those files via AddFont's $fontfile argument.
 */
final class NotoTcpdfFont
{
    public static function dir(): string
    {
        return dirname(__DIR__, 2).'/resources/fonts/noto/tcpdf/';
    }

    public static function regular(): string
    {
        self::ensure();

        return 'notosans';
    }

    public static function bold(): string
    {
        self::ensure();

        return 'notosansb';
    }

    public static function regularFile(): string
    {
        return self::dir().'notosans.php';
    }

    public static function boldFile(): string
    {
        return self::dir().'notosansb.php';
    }

    public static function italic(): string
    {
        self::ensure();

        return 'notosans';
    }

    public static function italicFile(): string
    {
        return self::dir().'notosansi.php';
    }

    public static function register(\TCPDF $pdf): void
    {
        $pdf->AddFont(self::regular(), '', self::regularFile());
        $pdf->AddFont(self::bold(), '', self::boldFile());
        $pdf->AddFont(self::regular(), 'I', self::italicFile());
    }

    private static function ensure(): void
    {
        $dir = self::dir();
        $regular = $dir.'notosans.php';
        $bold = $dir.'notosansb.php';
        $italic = $dir.'notosansi.php';
        if (is_file($regular) && is_file($bold) && is_file($italic)) {
            return;
        }

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $ttfDir = dirname($dir);
        if (! is_file($regular) && is_file($ttfDir.'/NotoSans-Regular.ttf')) {
            \TCPDF_FONTS::addTTFfont($ttfDir.'/NotoSans-Regular.ttf', 'TrueTypeUnicode', '', 32, $dir);
        }
        if (! is_file($bold) && is_file($ttfDir.'/NotoSans-Bold.ttf')) {
            \TCPDF_FONTS::addTTFfont($ttfDir.'/NotoSans-Bold.ttf', 'TrueTypeUnicode', '', 32, $dir);
        }
        if (! is_file($italic) && is_file($ttfDir.'/NotoSans-Italic.ttf')) {
            \TCPDF_FONTS::addTTFfont($ttfDir.'/NotoSans-Italic.ttf', 'TrueTypeUnicode', '', 32, $dir);
        }
    }
}
