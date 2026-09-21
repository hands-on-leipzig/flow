<?php

namespace App\Mail;

final class MailBrand
{
    public const FONT_STACK = "Uniform, Poppins, 'Segoe UI', Arial, Helvetica, sans-serif";

    public static function flowLogoSrc(): string
    {
        return self::fileDataUri(public_path('flow/flow.png'), 'image/png');
    }

    public static function hotLogoSrc(): string
    {
        return self::fileDataUri(public_path('flow/hot.png'), 'image/png');
    }

    public static function fontFaceCss(): string
    {
        $regular = self::fileDataUri(resource_path('fonts/Uniform-Regular.otf'), 'font/otf');
        $bold = self::fileDataUri(resource_path('fonts/Uniform-Bold.otf'), 'font/otf');
        if ($regular === '' || $bold === '') {
            return '';
        }

        return '@font-face{font-family:Uniform;src:url('.$regular.') format("opentype");font-weight:400;font-style:normal;}'
            .'@font-face{font-family:Uniform;src:url('.$bold.') format("opentype");font-weight:700;font-style:normal;}';
    }

    private static function fileDataUri(string $path, string $mime): string
    {
        if (! is_readable($path)) {
            return '';
        }

        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return '';
        }

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }
}
