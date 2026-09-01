<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventVolunteerRoster;
use App\Models\VolunteerPerson;
use App\Services\SeasonService;
use App\Support\VolunteerMealOptions;
use App\Support\VolunteerRosterColumns;
use App\Support\VolunteerRosterCustomFields;
use App\Support\VolunteerRosterDetailFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class VolunteerPublicFormController extends Controller
{
    public function lookup(Request $request, string $slug): JsonResponse
    {
        $email = strtolower(trim((string) $request->query('email', '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return response()->json(['error' => 'Ungültige E-Mail-Adresse.'], 422);
        }

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
            ->with(['detail', 'fieldValues.field'])
            ->first();

        if (! $roster) {
            abort(404, 'Nicht auf der Helfer:innenliste.');
        }

        $customFields = VolunteerRosterColumns::customFieldsForEvent($event->id);
        $mealOptions = VolunteerMealOptions::bootstrapForEvent($event->id);
        $columns = collect(VolunteerRosterColumns::tablePayloadForEvent($event->id))
            ->reject(fn (array $column) => in_array($column['key'], ['name', 'role'], true))
            ->values()
            ->all();

        return response()->json([
            'person' => [
                'first_name' => $person->first_name,
                'last_name' => $person->last_name,
                'mobile' => $person->mobile,
            ],
            'detail' => VolunteerRosterDetailFields::serialize($roster->detail),
            'custom' => $this->customValuesForRow($roster, $customFields),
            'meal_options' => $mealOptions,
            'fields' => $columns,
        ]);
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

    /**
     * @param  Collection<int, \App\Models\EventVolunteerField>  $customFields
     * @return array<string, mixed>
     */
    private function customValuesForRow(EventVolunteerRoster $row, Collection $customFields): array
    {
        $valuesByFieldId = $row->fieldValues->keyBy('event_volunteer_field');
        $payload = [];

        foreach ($customFields as $field) {
            $stored = $valuesByFieldId->get($field->id)?->value;
            $payload[$field->field_key] = VolunteerRosterCustomFields::apiValue($field, $stored);
        }

        return $payload;
    }
}
