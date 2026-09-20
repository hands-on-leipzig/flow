<?php

namespace App\Services;

use App\Mail\VolunteerInquiryAcceptedMail;
use App\Mail\VolunteerInquiryDeclinedMail;
use App\Mail\VolunteerInquiryPlannerMail;
use App\Models\Event;
use App\Models\EventVolunteerRoster;
use App\Models\User;
use App\Models\VolunteerInquiry;
use App\Models\VolunteerPerson;
use App\Support\GermanMobileNumber;
use App\Support\PublicHelperSearchPayload;
use Carbon\Carbon;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class VolunteerInquiryService
{
    /**
     * @param  array{event_id: int, role: string, first_name: string, last_name: string, email: string, mobile?: string|null, message?: string|null, draht_id?: int|null}  $input
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

        $drahtId = $this->positiveInt($input['draht_id'] ?? null);

        $inquiry = DB::transaction(function () use ($event, $role, $email, $firstName, $lastName, $message, $mobileResult, $drahtId) {
            $existing = VolunteerInquiry::query()
                ->where('event', $event->id)
                ->where('email', $email)
                ->where('role', $role)
                ->where('status', VolunteerInquiry::STATUS_PENDING)
                ->first();

            $payload = [
                'event' => $event->id,
                'volunteer_person' => null,
                'draht_id' => $drahtId,
                'role' => $role,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'mobile' => $mobileResult['normalized'],
                'message' => $message,
                'status' => VolunteerInquiry::STATUS_PENDING,
                'decided_at' => null,
            ];

            if ($existing) {
                $existing->fill($payload);
                $existing->save();

                return $existing;
            }

            $payload['created_at'] = now();

            return VolunteerInquiry::create($payload);
        });

        if ($inquiry->wasRecentlyCreated) {
            $this->notifyPlanners($event, $inquiry);
        }

        return ['inquiry_id' => (int) $inquiry->id];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingForEvent(Event $event): array
    {
        return VolunteerInquiry::query()
            ->where('event', $event->id)
            ->where('status', VolunteerInquiry::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (VolunteerInquiry $inquiry) => $this->serialize($inquiry))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function accept(Event $event, VolunteerInquiry $inquiry): array
    {
        $this->assertInquiryOnEvent($event, $inquiry);
        $this->assertPending($inquiry);

        $inquiry = DB::transaction(function () use ($event, $inquiry) {
            $person = $this->resolveOrCreatePerson($event, $inquiry);

            EventVolunteerRoster::firstOrCreate(
                [
                    'event' => $event->id,
                    'volunteer_person' => $person->id,
                ],
                [
                    'created_at' => now(),
                ]
            );

            $inquiry->volunteer_person = $person->id;
            $inquiry->status = VolunteerInquiry::STATUS_ACCEPTED;
            $inquiry->decided_at = now();
            $inquiry->save();

            return $inquiry->fresh();
        });

        $this->notifyInquirer(
            $inquiry,
            new VolunteerInquiryAcceptedMail(
                eventName: $this->eventName($event),
                personName: $this->personName($inquiry),
            ),
        );

        return $this->serialize($inquiry);
    }

    /**
     * @return array<string, mixed>
     */
    public function decline(Event $event, VolunteerInquiry $inquiry): array
    {
        $this->assertInquiryOnEvent($event, $inquiry);
        $this->assertPending($inquiry);

        $inquiry->status = VolunteerInquiry::STATUS_DECLINED;
        $inquiry->decided_at = now();
        $inquiry->save();

        $this->notifyInquirer(
            $inquiry,
            new VolunteerInquiryDeclinedMail(
                eventName: $this->eventName($event),
            ),
        );

        return $this->serialize($inquiry);
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(VolunteerInquiry $inquiry): array
    {
        return [
            'id' => (int) $inquiry->id,
            'event' => (int) $inquiry->event,
            'role' => $inquiry->role,
            'first_name' => $inquiry->first_name,
            'last_name' => $inquiry->last_name,
            'email' => $inquiry->email,
            'mobile' => $inquiry->mobile,
            'message' => $inquiry->message,
            'draht_id' => $inquiry->hasAccount() ? (int) $inquiry->draht_id : null,
            'has_account' => $inquiry->hasAccount(),
            'status' => $inquiry->status,
            'created_at' => optional($inquiry->created_at)?->toIso8601String(),
        ];
    }

    private function resolveOrCreatePerson(Event $event, VolunteerInquiry $inquiry): VolunteerPerson
    {
        $rp = (int) $event->regional_partner;
        $drahtId = $inquiry->hasAccount() ? (int) $inquiry->draht_id : null;

        if ($drahtId) {
            $person = VolunteerPerson::query()
                ->where('regional_partner', $rp)
                ->where('draht_id', $drahtId)
                ->first();
            if ($person) {
                if ($inquiry->mobile && ! $person->mobile) {
                    $person->mobile = $inquiry->mobile;
                    $person->save();
                }

                return $person;
            }
        }

        return VolunteerPerson::create([
            'regional_partner' => $rp,
            'draht_id' => $drahtId,
            'first_name' => $inquiry->first_name,
            'last_name' => $inquiry->last_name,
            'email' => $inquiry->email,
            'mobile' => $inquiry->mobile,
        ]);
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function assertInquiryOnEvent(Event $event, VolunteerInquiry $inquiry): void
    {
        if ((int) $inquiry->event !== (int) $event->id) {
            abort(404);
        }
    }

    private function assertPending(VolunteerInquiry $inquiry): void
    {
        if (! $inquiry->isPending()) {
            throw ValidationException::withMessages([
                'inquiry' => 'Diese Anfrage ist bereits entschieden.',
            ]);
        }
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

    private function notifyPlanners(Event $event, VolunteerInquiry $inquiry): void
    {
        $emails = $this->plannerEmails($event);
        if ($emails === []) {
            return;
        }

        $this->sendMailSafely($emails, new VolunteerInquiryPlannerMail(
            eventName: $this->eventName($event),
            personName: $this->personName($inquiry),
            role: (string) $inquiry->role,
        ));
    }

    private function notifyInquirer(VolunteerInquiry $inquiry, Mailable $mail): void
    {
        $this->sendMailSafely((string) $inquiry->email, $mail);
    }

    /**
     * @return list<string>
     */
    private function plannerEmails(Event $event): array
    {
        $rpId = (int) $event->regional_partner;
        if ($rpId <= 0) {
            return [];
        }

        $userIds = DB::table('user_regional_partner')
            ->where('regional_partner', $rpId)
            ->pluck('user');

        if ($userIds->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn ($email) => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  string|list<string>  $to
     */
    private function sendMailSafely(string|array $to, Mailable $mail): void
    {
        $recipients = array_values(array_filter(
            array_map(
                fn ($email) => strtolower(trim((string) $email)),
                is_array($to) ? $to : [$to],
            ),
            fn ($email) => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        ));

        if ($recipients === []) {
            return;
        }

        try {
            Mail::to($recipients)->send($mail);
        } catch (\Throwable $e) {
            Log::error('Volunteer inquiry mail failed', [
                'mailable' => $mail::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function eventName(Event $event): string
    {
        $name = trim((string) $event->name);

        return $name !== '' ? $name : 'Veranstaltung';
    }

    private function personName(VolunteerInquiry $inquiry): string
    {
        return trim($inquiry->first_name.' '.$inquiry->last_name);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
