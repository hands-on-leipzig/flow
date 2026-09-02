<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventVolunteerField;
use App\Models\EventVolunteerFieldValue;
use App\Models\EventVolunteerRoster;
use App\Models\EventVolunteerRosterDetail;
use App\Models\VolunteerPerson;
use App\Services\SeasonService;
use App\Support\GermanMobileNumber;
use App\Support\VolunteerCollectOptions;
use App\Support\VolunteerMealOptions;
use App\Support\VolunteerRosterColumns;
use App\Support\VolunteerRosterCustomFields;
use App\Support\VolunteerRosterDetailFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VolunteerPublicFormController extends Controller
{
    public function lookup(Request $request, string $slug): JsonResponse
    {
        $email = $this->normalizeEmail((string) $request->query('email', ''));
        if ($email === null) {
            return response()->json(['error' => 'Ungültige E-Mail-Adresse.'], 422);
        }

        ['event' => $event, 'person' => $person, 'roster' => $roster] = $this->resolveRosterMember($slug, $email);
        $roster->load(['detail', 'fieldValues.field']);

        $customFields = VolunteerRosterColumns::customFieldsForEvent($event->id);
        $mealOptions = VolunteerMealOptions::bootstrapForEvent($event->id);
        $columns = collect(VolunteerRosterColumns::tablePayloadForEvent($event->id))
            ->reject(fn (array $column) => in_array($column['key'], ['name', 'role'], true))
            ->filter(function (array $column) {
                if (($column['kind'] ?? '') === 'custom') {
                    return (bool) ($column['public_form'] ?? false);
                }

                return true;
            })
            ->values()
            ->all();

        return response()->json([
            'person' => $this->serializePerson($person),
            'detail' => VolunteerRosterDetailFields::serialize($roster->detail),
            'custom' => VolunteerRosterCustomFields::apiValuesForRow($roster, $customFields),
            'meal_options' => $mealOptions,
            'fields' => $columns,
        ]);
    }

    /**
     * Public save scoped by slug + email (same as lookup).
     * Real OTP slice will replace this with an email-scoped session token.
     */
    public function save(Request $request, string $slug): JsonResponse
    {
        $email = $this->normalizeEmail((string) $request->input('email', ''));
        if ($email === null) {
            return response()->json(['error' => 'Ungültige E-Mail-Adresse.'], 422);
        }

        ['event' => $event, 'person' => $person, 'roster' => $roster] = $this->resolveRosterMember($slug, $email);
        $roster->load(['detail', 'fieldValues.field']);

        $personInput = $request->input('person', []);
        if (! is_array($personInput)) {
            return response()->json(['error' => 'Ungültige Personendaten.'], 422);
        }

        $firstName = trim((string) ($personInput['first_name'] ?? ''));
        $lastName = trim((string) ($personInput['last_name'] ?? ''));
        if ($firstName === '' || mb_strlen($firstName) > 100) {
            return response()->json(['error' => 'Ungültiger Vorname.'], 422);
        }
        if ($lastName === '' || mb_strlen($lastName) > 100) {
            return response()->json(['error' => 'Ungültiger Name.'], 422);
        }

        $organization = array_key_exists('organization', $personInput)
            ? $this->nullableTrim($personInput['organization'])
            : $person->organization;
        if ($organization !== null && mb_strlen($organization) > 255) {
            return response()->json(['error' => 'Organisation darf maximal 255 Zeichen haben.'], 422);
        }

        $mobileResult = GermanMobileNumber::validateAndNormalize(
            array_key_exists('mobile', $personInput) ? $personInput['mobile'] : $person->mobile
        );
        if (! $mobileResult['ok']) {
            return response()->json(['error' => $mobileResult['error']], 422);
        }

        $detailInput = $request->input('detail', []);
        if (! is_array($detailInput)) {
            return response()->json(['error' => 'Ungültige Detaildaten.'], 422);
        }

        $existingDetail = $roster->detail;
        $mealOptions = VolunteerMealOptions::optionsForEvent($event->id);
        if ($mealOptions->isEmpty()) {
            VolunteerMealOptions::bootstrapForEvent($event->id);
            $mealOptions = VolunteerMealOptions::optionsForEvent($event->id);
        }

        $collectShirt = VolunteerCollectOptions::collectsTShirt($event);
        $collectMeal = VolunteerCollectOptions::collectsMeal($event);

        if (! $collectShirt && (array_key_exists('t_shirt_cut', $detailInput) || array_key_exists('t_shirt_size', $detailInput))) {
            return response()->json(['error' => 'T-Shirt-Angaben sind für diese Veranstaltung deaktiviert.'], 422);
        }
        if (! $collectMeal && array_key_exists('meal', $detailInput)) {
            return response()->json(['error' => 'Essenswahl ist für diese Veranstaltung deaktiviert.'], 422);
        }

        $detailPayload = [
            't_shirt_cut' => $collectShirt
                ? (array_key_exists('t_shirt_cut', $detailInput) ? $detailInput['t_shirt_cut'] : $existingDetail?->t_shirt_cut)
                : null,
            't_shirt_size' => $collectShirt
                ? (array_key_exists('t_shirt_size', $detailInput) ? $detailInput['t_shirt_size'] : $existingDetail?->t_shirt_size)
                : null,
            'meal' => $collectMeal
                ? (array_key_exists('meal', $detailInput) ? $detailInput['meal'] : $existingDetail?->meal)
                : null,
            'photo_consent' => $existingDetail?->photo_consent,
        ];

        $detailValidation = VolunteerRosterDetailFields::validate(
            $detailPayload,
            VolunteerMealOptions::allowedValues($mealOptions),
        );
        if (! $detailValidation['ok']) {
            return response()->json(['error' => $detailValidation['error']], 422);
        }

        $customInput = $request->input('custom', []);
        if (! is_array($customInput)) {
            return response()->json(['error' => 'Ungültige Zusatzfelder.'], 422);
        }

        $writableCustomFields = $this->writableCustomFieldsForEvent($event->id);
        $writableKeys = $writableCustomFields->pluck('field_key')->all();

        foreach (array_keys($customInput) as $fieldKey) {
            if (! in_array($fieldKey, $writableKeys, true)) {
                return response()->json(['error' => 'Unbekanntes Zusatzfeld.'], 422);
            }
        }

        $validatedCustom = [];
        foreach ($writableCustomFields as $field) {
            if (! array_key_exists($field->field_key, $customInput)) {
                continue;
            }

            $validation = VolunteerRosterCustomFields::validateValue($field, $customInput[$field->field_key]);
            if (! $validation['ok']) {
                return response()->json(['error' => $validation['error']], 422);
            }

            $validatedCustom[$field->field_key] = [
                'field' => $field,
                'stored' => $validation['stored'],
                'api' => $validation['api'],
            ];
        }

        DB::transaction(function () use (
            $person,
            $roster,
            $firstName,
            $lastName,
            $organization,
            $mobileResult,
            $detailValidation,
            $validatedCustom,
        ) {
            $person->fill([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'mobile' => $mobileResult['normalized'],
                'organization' => $organization,
            ]);
            $person->save();

            EventVolunteerRosterDetail::query()->updateOrCreate(
                ['event_volunteer_roster' => $roster->id],
                array_merge($detailValidation['data'], ['updated_at' => now()])
            );

            foreach ($validatedCustom as $entry) {
                /** @var EventVolunteerField $field */
                $field = $entry['field'];
                if ($entry['stored'] === null) {
                    EventVolunteerFieldValue::query()
                        ->where('event_volunteer_roster', $roster->id)
                        ->where('event_volunteer_field', $field->id)
                        ->delete();
                } else {
                    EventVolunteerFieldValue::query()->updateOrCreate(
                        [
                            'event_volunteer_roster' => $roster->id,
                            'event_volunteer_field' => $field->id,
                        ],
                        [
                            'value' => $entry['stored'],
                            'updated_at' => now(),
                        ]
                    );
                }
            }
        });

        $roster->refresh();
        $roster->unsetRelation('detail');
        $roster->unsetRelation('fieldValues');
        $roster->load(['detail', 'fieldValues.field']);
        $person->refresh();
        $customFields = VolunteerRosterColumns::customFieldsForEvent($event->id);

        return response()->json([
            'person' => $this->serializePerson($person),
            'detail' => VolunteerRosterDetailFields::serialize($roster->detail),
            'custom' => VolunteerRosterCustomFields::apiValuesForRow($roster, $customFields),
        ]);
    }

    /**
     * @return array{event: Event, person: VolunteerPerson, roster: EventVolunteerRoster}
     */
    private function resolveRosterMember(string $slug, string $email): array
    {
        $event = $this->eventBySlug($slug);
        if (! (bool) $event->public_volunteer_data_entry) {
            abort(404, 'Dateneingabe ist nicht verfügbar.');
        }

        $person = VolunteerPerson::query()
            ->where('regional_partner', $event->regional_partner)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $person) {
            abort(404, 'Nicht auf der Helfer:innenliste.');
        }

        $roster = EventVolunteerRoster::query()
            ->where('event', $event->id)
            ->where('volunteer_person', $person->id)
            ->first();

        if (! $roster) {
            abort(404, 'Nicht auf der Helfer:innenliste.');
        }

        return [
            'event' => $event,
            'person' => $person,
            'roster' => $roster,
        ];
    }

    private function normalizeEmail(string $email): ?string
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $normalized;
    }

    /**
     * @return Collection<int, EventVolunteerField>
     */
    private function writableCustomFieldsForEvent(int $eventId): Collection
    {
        $publicFormKeys = collect(VolunteerRosterColumns::tablePayloadForEvent($eventId))
            ->filter(fn (array $column) => ($column['kind'] ?? '') === 'custom' && ($column['public_form'] ?? true))
            ->pluck('field_key')
            ->filter()
            ->all();

        return VolunteerRosterColumns::customFieldsForEvent($eventId)
            ->filter(fn (EventVolunteerField $field) => in_array($field->field_key, $publicFormKeys, true))
            ->values();
    }

    /**
     * @return array{first_name: string, last_name: string, mobile: string|null, organization: string|null}
     */
    private function serializePerson(VolunteerPerson $person): array
    {
        return [
            'first_name' => $person->first_name,
            'last_name' => $person->last_name,
            'mobile' => $person->mobile,
            'organization' => $person->organization,
        ];
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function eventBySlug(string $slug): Event
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('season', SeasonService::currentSeasonId())
            ->first();

        if (! $event) {
            abort(404, 'Event not found');
        }

        return $event;
    }
}
