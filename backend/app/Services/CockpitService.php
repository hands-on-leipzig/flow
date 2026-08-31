<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Facades\Crypt;

class CockpitService
{
    public function generatePin(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function encryptPin(string $pin): string
    {
        return Crypt::encryptString($pin);
    }

    public function decryptPin(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null;
        }
    }

    public function ensurePin(Event $event): string
    {
        $pin = $this->decryptPin($event->cockpit_pin);
        if ($pin !== null && preg_match('/^\d{6}$/', $pin)) {
            return $pin;
        }

        $pin = $this->generatePin();
        $event->cockpit_pin = $this->encryptPin($pin);
        $event->save();

        return $pin;
    }

    public function settingsPayload(Event $event): array
    {
        $pin = $this->ensurePin($event);

        return [
            'event_id' => $event->id,
            'slug' => $event->slug,
            'has_slug' => $event->slug !== null && $event->slug !== '',
            'enabled' => (bool) $event->cockpit_enabled,
            'pin' => $pin,
            'app_path' => $event->slug ? '/'.$event->slug.'/cockpit' : null,
        ];
    }

    public function updateSettings(Event $event, array $data): array
    {
        if (array_key_exists('enabled', $data)) {
            $event->cockpit_enabled = (bool) $data['enabled'];
        }

        if (array_key_exists('pin', $data)) {
            $pin = preg_replace('/\D/', '', (string) $data['pin']);
            if (strlen($pin) !== 6) {
                throw new \InvalidArgumentException('PIN must be exactly 6 digits.');
            }
            $event->cockpit_pin = $this->encryptPin($pin);
        }

        if ($event->cockpit_enabled) {
            $this->ensurePin($event);
        }

        $event->save();

        return $this->settingsPayload($event->fresh());
    }

    public function verifyPin(Event $event, string $pin): bool
    {
        $expected = $this->decryptPin($event->cockpit_pin);
        if ($expected === null) {
            return false;
        }

        return hash_equals($expected, preg_replace('/\D/', '', $pin) ?? '');
    }

    public function makeSessionToken(Event $event): string
    {
        return Crypt::encryptString(json_encode([
            'event_id' => (int) $event->id,
            'issued_at' => now()->toIso8601String(),
        ]));
    }

    public function eventIdFromSessionToken(?string $token): ?int
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true);
            if (! is_array($payload) || ! isset($payload['event_id'])) {
                return null;
            }

            return (int) $payload['event_id'];
        } catch (\Throwable) {
            return null;
        }
    }
}
