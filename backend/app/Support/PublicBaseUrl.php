<?php

namespace App\Support;

/**
 * Host of public event links (QR, DRAHT, share UI).
 *
 * Production is the vanity host. Dev and Test follow the same DNS as subdomains.
 * Local laptop stays on FRONTEND_URL so QR codes still point at Vite.
 *
 * Servers that still have PUBLIC_URL=https://handson.tools/dev (or /test) are
 * rewritten to the subdomain so regenerate does not keep the old path form.
 */
class PublicBaseUrl
{
    public static function resolve(
        ?string $publicUrl,
        string $env,
        string $frontendUrl = 'http://localhost:5173',
        string $appUrl = 'http://localhost',
    ): string {
        $explicit = rtrim(trim((string) $publicUrl), '/');
        if ($explicit !== '') {
            return self::normalize($explicit);
        }

        return self::normalize(self::forEnvironment($env, $frontendUrl, $appUrl));
    }

    public static function forEnvironment(
        string $env,
        string $frontendUrl = 'http://localhost:5173',
        string $appUrl = 'http://localhost',
    ): string {
        $env = strtolower(trim($env));
        if ($env === 'production' || $env === 'prod') {
            return 'https://handson.tools';
        }

        $host = strtolower((string) (
            parse_url($frontendUrl, PHP_URL_HOST)
            ?: parse_url($appUrl, PHP_URL_HOST)
            ?: ''
        ));

        if (str_contains($host, 'test')) {
            return 'https://test.handson.tools';
        }

        if ($host !== '' && $host !== 'localhost' && $host !== '127.0.0.1' && $host !== '::1') {
            return 'https://dev.handson.tools';
        }

        $local = rtrim($frontendUrl, '/');

        return $local !== '' ? $local : 'http://localhost:5173';
    }

    public static function normalize(string $base): string
    {
        $base = rtrim(trim($base), '/');
        $host = strtolower((string) (parse_url($base, PHP_URL_HOST) ?? ''));
        $path = strtolower(trim((string) (parse_url($base, PHP_URL_PATH) ?? ''), '/'));

        if ($host === 'handson.tools' || $host === 'www.handson.tools') {
            if ($path === 'dev') {
                return 'https://dev.handson.tools';
            }
            if ($path === 'test') {
                return 'https://test.handson.tools';
            }
        }

        return $base;
    }
}
