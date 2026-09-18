<?php

namespace App\Services;

use App\Mail\PublicOtpMail;
use App\Models\Event;
use App\Models\EventVolunteerRoster;
use App\Models\VolunteerPerson;
use App\Support\TeamCoachLookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class PublicFormOtpService
{
    public const PURPOSE_VOLUNTEER = 'volunteer';

    public const PURPOSE_TEAM = 'team';

    public const CODE_TTL_MINUTES = 15;

    public const SESSION_TTL_MINUTES = 120;

    public const REQUEST_COOLDOWN_SECONDS = 45;

    public const TOKEN_HEADER = 'X-Public-Form-Token';

    public function eventForSlug(string $slug): Event
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('season', SeasonService::currentSeasonId())
            ->first();

        if (! $event) {
            abort(404, 'Veranstaltung nicht gefunden.');
        }

        return $event;
    }

    public function requestCode(string $purpose, Event $event, string $email): void
    {
        $email = $this->normalizeEmail($email);
        if ($email === null) {
            return;
        }

        $cooldownKey = $this->cooldownKey($purpose, (int) $event->id, $email);
        if (! Cache::add($cooldownKey, 1, self::REQUEST_COOLDOWN_SECONDS)) {
            return;
        }

        if (! $this->emailIsEligible($purpose, $event, $email)) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put(
            $this->codeKey($purpose, (int) $event->id, $email),
            hash('sha256', $code),
            now()->addMinutes(self::CODE_TTL_MINUTES),
        );

        try {
            Mail::to($email)->send(new PublicOtpMail(
                eventName: $this->eventName($event),
                code: $code,
            ));
        } catch (\Throwable $e) {
            Cache::forget($this->codeKey($purpose, (int) $event->id, $email));
            Log::error('Public OTP mail failed', [
                'purpose' => $purpose,
                'event' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function verifyCode(string $purpose, Event $event, string $email, string $code): ?string
    {
        $email = $this->normalizeEmail($email);
        if ($email === null) {
            return null;
        }

        $rateKey = sprintf('public-otp:verify:%s:%d:%s', $purpose, $event->id, $email);
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            abort(429, 'Zu viele Versuche. Bitte kurz warten.');
        }

        $digits = preg_replace('/\D/', '', $code) ?? '';
        $stored = Cache::get($this->codeKey($purpose, (int) $event->id, $email));
        if (! is_string($stored) || $digits === '' || ! hash_equals($stored, hash('sha256', $digits))) {
            RateLimiter::hit($rateKey, 60);

            return null;
        }

        RateLimiter::clear($rateKey);
        Cache::forget($this->codeKey($purpose, (int) $event->id, $email));

        return $this->issueVerifiedSession($purpose, (int) $event->id, $email);
    }

    public function issueVerifiedSession(string $purpose, int $eventId, string $email): string
    {
        $token = bin2hex(random_bytes(32));
        Cache::put(
            $this->sessionKey($purpose, $eventId, $email),
            hash('sha256', $token),
            now()->addMinutes(self::SESSION_TTL_MINUTES),
        );

        return $token;
    }

    public function assertVerified(Request $request, string $purpose, Event $event, string $email): void
    {
        $token = $this->tokenFromRequest($request);
        $stored = Cache::get($this->sessionKey($purpose, (int) $event->id, $email));
        if (
            $token === ''
            || ! is_string($stored)
            || ! hash_equals($stored, hash('sha256', $token))
        ) {
            abort(401, 'Sitzung ungültig.');
        }
    }

    public function tokenFromRequest(Request $request): string
    {
        $header = trim((string) $request->header(self::TOKEN_HEADER, ''));
        if ($header !== '') {
            return $header;
        }

        return trim((string) $request->input('token', ''));
    }

    public function normalizeEmail(string $email): ?string
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $normalized;
    }

    private function emailIsEligible(string $purpose, Event $event, string $email): bool
    {
        if ($purpose === self::PURPOSE_VOLUNTEER) {
            if (! (bool) $event->public_volunteer_data_entry) {
                return false;
            }

            $person = VolunteerPerson::query()
                ->where('regional_partner', $event->regional_partner)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if (! $person) {
                return false;
            }

            return EventVolunteerRoster::query()
                ->where('event', $event->id)
                ->where('volunteer_person', $person->id)
                ->exists();
        }

        if ($purpose === self::PURPOSE_TEAM) {
            if (! (bool) $event->public_team_data_entry) {
                return false;
            }

            return TeamCoachLookup::teamsForEmail($event, $email)->isNotEmpty();
        }

        return false;
    }

    private function eventName(Event $event): string
    {
        $name = trim((string) $event->name);

        return $name !== '' ? $name : 'Veranstaltung';
    }

    private function codeKey(string $purpose, int $eventId, string $email): string
    {
        return sprintf('public-otp:code:%s:%d:%s', $purpose, $eventId, $email);
    }

    private function sessionKey(string $purpose, int $eventId, string $email): string
    {
        return sprintf('public-otp:session:%s:%d:%s', $purpose, $eventId, $email);
    }

    private function cooldownKey(string $purpose, int $eventId, string $email): string
    {
        return sprintf('public-otp:cooldown:%s:%d:%s', $purpose, $eventId, $email);
    }
}
