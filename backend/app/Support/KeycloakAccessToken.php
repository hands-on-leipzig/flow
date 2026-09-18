<?php

namespace App\Support;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class KeycloakAccessToken
{
    public const ISSUER = 'https://sso.hands-on-technology.org/realms/master';

    /**
     * Decode a Bearer token when present. Invalid or missing tokens yield null
     * so public HERO/JOIN callers can still submit as guests.
     *
     * @return array<string, mixed>|null
     */
    public static function optionalClaims(Request $request): ?array
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = substr($header, 7);
        if ($token === '') {
            return null;
        }

        $publicKeyPath = base_path((string) config('services.keycloak.public_key_path'));
        if ($publicKeyPath === '' || ! is_file($publicKeyPath)) {
            return null;
        }

        $publicKey = file_get_contents($publicKeyPath);
        if (! is_string($publicKey) || $publicKey === '') {
            return null;
        }

        try {
            $decoded = JWT::decode($token, new Key($publicKey, 'RS256'));
            $claims = (array) $decoded;
        } catch (Throwable $e) {
            Log::info('Ignoring invalid Keycloak token on public request', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (($claims['iss'] ?? '') !== self::ISSUER) {
            return null;
        }

        return $claims;
    }

    /**
     * Email claim from a valid Keycloak access token, or null.
     */
    public static function email(Request $request): ?string
    {
        $claims = self::optionalClaims($request);
        if ($claims === null) {
            return null;
        }

        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }
}
