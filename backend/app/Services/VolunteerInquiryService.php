<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventVolunteerRoster;
use App\Models\VolunteerInquiry;
use App\Models\VolunteerPerson;
use App\Support\GermanMobileNumber;
use App\Support\PublicHelperSearchPayload;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VolunteerInquiryService
{
    /**
     * @param  array{event_id: int, role: string, first_name: string, last_name: string, email: string, mobile?: string|null, message?: string|null}  $input
     * @return array{inquiry_id: int}
     */
    public function submit(array $input): array
    {
        $event = Event::query()->with('regionalPartner')->find((int) ($input['event_id'] ?? 0));
        if (! $event) {
            throw ValidationException::withMessages([
                'event_id' => 'Veranstaltung nicht gefunden.',
            ]);
        }

        $this->assertEventAcceptsInquiries($event);

        $role = trim((string) ($input['role'] ?? ''));
        if ($role === '' || ! $this->roleIsOpen($event, $role)) {
            throw ValidationException::withMessages([
                'role' => 'Diese Rolle wird gerade nicht gesucht.',
            ]);
        }

        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $firstName = trim((string) ($input['first_name'] ?? ''));
        $lastName = trim((string) ($input['last_name'] ?? ''));
        $message = $this->nullableTrim($input['message'] ?? null);

        $mobileResult = GermanMobileNumber::validateAndNormalize($input['mobile'] ?? null);
        if (! $mobileResult['ok']) {
            throw ValidationException::withMessages([
                'mobile' => $mobileResult['error'],
            ]);
        }

        $inquiry = DB::transaction(function () use ($event, $role, $email, $firstName, $lastName, $message, $mobileResult) {
            $person = VolunteerPerson::query()
                ->where('regional_partner', $event->regional_partner)
                ->where('email', $email)
                ->first();

            if (! $person) {
                $person = VolunteerPerson::create([
                    'regional_partner' => $event->regional_partner,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'mobile' => $mobileResult['normalized'],
                ]);
            } elseif ($mobileResult['normalized'] && ! $person->mobile) {
                $person->mobile = $mobileResult['normalized'];
                $person->save();
            }

            EventVolunteerRoster::firstOrCreate(
                [
                    'event' => $event->id,
                    'volunteer_person' => $person->id,
                ],
                [
                    'created_at' => now(),
                ]
            );

            return VolunteerInquiry::create([
                'event' => $event->id,
                'volunteer_person' => $person->id,
                'role' => $role,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'mobile' => $mobileResult['normalized'],
                'message' => $message,
                'created_at' => now(),
            ]);
        });

        return ['inquiry_id' => (int) $inquiry->id];
    }

    private function assertEventAcceptsInquiries(Event $event): void
    {
        if (! (bool) $event->public_helper_search) {
            throw ValidationException::withMessages([
                'event_id' => 'Für diese Veranstaltung ist keine Helfer:innen-Suche veröffentlicht.',
            ]);
        }

        $seasonId = SeasonService::currentSeasonId();
        if ((int) $event->season !== (int) $seasonId) {
            throw ValidationException::withMessages([
                'event_id' => 'Veranstaltung nicht gefunden.',
            ]);
        }

        if (! $event->date) {
            throw ValidationException::withMessages([
                'event_id' => 'Veranstaltung nicht gefunden.',
            ]);
        }

        $start = Carbon::parse($event->date)->startOfDay();
        $days = max((int) ($event->days ?: 1), 1);
        $end = $start->copy()->addDays($days - 1)->endOfDay();
        if ($end->lt(Carbon::today())) {
            throw ValidationException::withMessages([
                'event_id' => 'Diese Veranstaltung liegt in der Vergangenheit.',
            ]);
        }
    }

    private function roleIsOpen(Event $event, string $role): bool
    {
        $payload = PublicHelperSearchPayload::forEvent($event);
        foreach ($payload['scopes'] ?? [] as $scope) {
            foreach ($scope['roles'] ?? [] as $openRole) {
                if (strcasecmp((string) $openRole, $role) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
