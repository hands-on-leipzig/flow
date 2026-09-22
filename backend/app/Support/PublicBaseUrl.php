<?php

namespace App\Support;

/**
 * Host of public event links (QR, DRAHT, share UI) when PUBLIC_URL is unset.
 *
 * Production is the vanity host. Dev and Test follow the same DNS as subdomains.
 * Local laptop stays on FRONTEND_URL so QR codes still point at Vite.
 */
class PublicBaseUrl
{
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
}
