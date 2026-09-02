<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTeamField;
use App\Models\EventTeamFieldValue;
use App\Models\Team;
use App\Support\TeamCoachLookup;
use App\Support\TeamDataColumns;
use App\Support\TeamDataCustomFields;
use App\Support\TeamDataIndex;
use App\Support\TeamMealCounts;
use App\Support\TeamPeopleCounts;
use App\Support\TeamPhotoCounts;
use App\Support\VolunteerCollectOptions;
use App\Support\VolunteerMealOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeamPublicFormController extends Controller
{
    public function lookup(Request $request, string $slug): JsonResponse
    {
        $email = $this->normalizeEmail((string) $request->query('email', ''));
        if ($email === null) {
            return response()->json(['error' => 'Ungültige E-Mail-Adresse.'], 422);
        }

        $event = $this->eventBySlug($slug);
        $teams = $this->resolveCoachTeams($event, $email);

        $teamRows = $this->serializeTeamList($event, $teams);
        $payload = ['teams' => $teamRows];

        if ($teams->count() === 1) {
            $payload['form'] = $this->formPayload($event, $teams->first());
        }

        return response()->json($payload);
    }

    public function team(Request $request, string $slug, Team $team): JsonResponse
    {
        $email = $this->normalizeEmail((string) $request->query('email', ''));
        if ($email === null) {
            return response()->json(['error' => 'Ungültige E-Mail-Adresse.'], 422);
        }

        $event = $this->eventBySlug($slug);
        $teams = $this->resolveCoachTeams($event, $email);
        if (! $teams->contains(fn (Team $item) => (int) $item->id === (int) $team->id)) {
            abort(404, 'Team nicht gefunden.');
        }
        if ((int) $team->event !== (int) $event->id) {
            abort(404, 'Team nicht gefunden.');
        }

        return response()->json([
            'form' => $this->formPayload($event, $team),
        ]);
    }

    public function save(Request $request, string $slug): JsonResponse
    {
        $email = $this->normalizeEmail((string) $request->input('email', ''));
        if ($email === null) {
            return response()->json(['error' => 'Ungültige E-Mail-Adresse.'], 422);
        }

        $event = $this->eventBySlug($slug);
        $teamId = (int) $request->input('team', 0);
        if ($teamId <= 0) {
            return response()->json(['error' => 'Team ist erforderlich.'], 422);
        }

        $teams = $this->resolveCoachTeams($event, $email);
        $team = $teams->first(fn (Team $item) => (int) $item->id === $teamId);
        if (! $team) {
            abort(404, 'Team nicht gefunden.');
        }

        $peopleCounts = TeamPeopleCounts::countsByTeamIdForEvent($event);
        $peopleCount = $peopleCounts[$team->id] ?? null;

        $collectMeal = VolunteerCollectOptions::collectsMeal($event);
        $hasPhoto = $request->has('photo_consent');
        $hasMeals = $request->has('meals');

        if (($hasPhoto || $hasMeals) && $peopleCount === null) {
            return response()->json(['error' => 'Personenanzahl für dieses Team fehlt.'], 422);
        }

        if ($hasPhoto) {
            $validation = TeamPhotoCounts::validateCountMap($request->input('photo_consent'));
            if (! $validation['ok']) {
                return response()->json(['error' => $validation['error']], 422);
            }
            $sum = array_sum($validation['api']);
            if ($sum !== (int) $peopleCount) {
                return response()->json(['error' => 'Foto Erlaubnis muss in der Summe der Personenanzahl entsprechen.'], 422);
            }
            try {
                TeamPhotoCounts::replaceForTeam($team->id, $validation['api']);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['error' => $e->getMessage()], 422);
            }
        }

        if ($hasMeals) {
            if (! $collectMeal) {
                return response()->json(['error' => 'Essen ist für diese Veranstaltung deaktiviert.'], 422);
            }
            $meals = $request->input('meals');
            if (! is_array($meals)) {
                return response()->json(['error' => 'Ungültige Essensdaten.'], 422);
            }
            $normalized = [];
            foreach ($meals as $key => $count) {
                $normalized[(string) $key] = (int) $count;
            }
            $sum = array_sum($normalized);
            if ($sum !== (int) $peopleCount) {
                return response()->json(['error' => 'Essen muss in der Summe der Personenanzahl entsprechen.'], 422);
            }
            try {
                TeamMealCounts::replaceForTeam($team->id, $event->id, $normalized);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['error' => $e->getMessage()], 422);
            }
        }

        if ($request->has('custom')) {
            $custom = $request->input('custom');
            if (! is_array($custom)) {
                return response()->json(['error' => 'Ungültige Zusatzfelder.'], 422);
            }

            $writable = $this->writableCustomFieldsForEvent($event->id)->keyBy('field_key');

            try {
                DB::transaction(function () use ($custom, $writable, $team) {
                    foreach ($custom as $fieldKey => $value) {
                        $fieldKey = (string) $fieldKey;
                        /** @var EventTeamField|null $field */
                        $field = $writable->get($fieldKey);
                        if (! $field) {
                            throw new \InvalidArgumentException('Unbekanntes Zusatzfeld: '.$fieldKey);
                        }

                        $validation = TeamDataCustomFields::validateValue($field, $value);
                        if (! $validation['ok']) {
                            throw new \InvalidArgumentException($validation['error']);
                        }

                        if ($validation['stored'] === null) {
                            EventTeamFieldValue::query()
                                ->where('team', $team->id)
                                ->where('event_team_field', $field->id)
                                ->delete();
                            continue;
                        }

                        EventTeamFieldValue::query()->updateOrCreate(
                            ['team' => $team->id, 'event_team_field' => $field->id],
                            ['value' => $validation['stored'], 'updated_at' => now()],
                        );
                    }
                });
            } catch (\InvalidArgumentException $e) {
                return response()->json(['error' => $e->getMessage()], 422);
            }
        }

        return response()->json([
            'form' => $this->formPayload($event, $team),
        ]);
    }

    /**
     * @return Collection<int, Team>
     */
    private function resolveCoachTeams(Event $event, string $email): Collection
    {
        $teams = TeamCoachLookup::teamsForEmail($event, $email);
        if ($teams->isEmpty()) {
            abort(404, 'Kein Team für diese E-Mail gefunden.');
        }

        return $teams;
    }

    private function eventBySlug(string $slug): Event
    {
        $event = Event::query()->where('slug', $slug)->first();
        if (! $event) {
            abort(404, 'Veranstaltung nicht gefunden.');
        }
        if (! (bool) $event->public_team_data_entry) {
            abort(404, 'Dateneingabe ist nicht verfügbar.');
        }

        return $event;
    }

    /**
     * @param  Collection<int, Team>  $teams
     * @return list<array<string, mixed>>
     */
    private function serializeTeamList(Event $event, Collection $teams): array
    {
        $peopleCounts = TeamPeopleCounts::countsByTeamIdForEvent($event);
        $meta = TeamDataIndex::programMeta($event);

        return $teams->map(function (Team $team) use ($peopleCounts, $meta) {
            $firstProgramId = (int) $team->first_program;

            return [
                'id' => $team->id,
                'name' => $team->name,
                'team_number_hot' => $team->team_number_hot !== null ? (int) $team->team_number_hot : null,
                'organization' => $team->organization,
                'program_label' => $meta['labels'][$firstProgramId] ?? '',
                'people_count' => $peopleCounts[$team->id] ?? null,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(Event $event, Team $team): array
    {
        $row = TeamDataIndex::teamPayload($event, $team);
        $collectMeal = VolunteerCollectOptions::collectsMeal($event);

        $columns = collect(TeamDataColumns::tablePayloadForEvent($event->id))
            ->filter(function (array $column) {
                if (($column['kind'] ?? '') === 'photo') {
                    return true;
                }
                if (($column['kind'] ?? '') === 'meal') {
                    return true;
                }
                if (($column['kind'] ?? '') === 'custom') {
                    return (bool) ($column['public_form'] ?? false);
                }

                return false;
            })
            ->values()
            ->all();

        $mealOptions = VolunteerMealOptions::optionsForEvent($event->id);
        if ($mealOptions->isEmpty()) {
            VolunteerMealOptions::bootstrapForEvent($event->id);
            $mealOptions = VolunteerMealOptions::optionsForEvent($event->id);
        }

        $writableKeys = $this->writableCustomFieldsForEvent($event->id)->pluck('field_key')->all();
        $custom = [];
        foreach ($writableKeys as $key) {
            $custom[$key] = $row['custom'][$key] ?? null;
        }

        return [
            'team' => [
                'id' => $row['id'],
                'name' => $row['name'],
                'team_number_hot' => $row['team_number_hot'],
                'organization' => $row['organization'],
                'program_label' => $row['program_label'],
                'people_count' => $row['people_count'],
            ],
            'columns' => $columns,
            'photo_consent' => $row['photo_consent'] ?? TeamPhotoCounts::mapForTeamWithDefaults($team->id),
            'meals' => $collectMeal
                ? ($row['meals'] ?? TeamMealCounts::mapForTeamWithCatalog($team->id, $event->id))
                : [],
            'custom' => $custom,
            'meal_options' => VolunteerMealOptions::serializeList($mealOptions),
            'touched' => [
                'photo' => (bool) ($row['touched']['photo'] ?? false),
                'meal' => (bool) ($row['touched']['meal'] ?? false),
                'custom' => $row['touched']['custom'] ?? [],
            ],
        ];
    }

    /**
     * @return Collection<int, EventTeamField>
     */
    private function writableCustomFieldsForEvent(int $eventId): Collection
    {
        return EventTeamField::query()
            ->where('event', $eventId)
            ->where('public_form', true)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    private function normalizeEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }
}
