<?php

namespace App\Http\Controllers\Api;

use App\Export\Spreadsheet\SpreadsheetResponse;
use App\Export\Teams\TeamDataSpreadsheetSource;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTeamField;
use App\Models\EventTeamFieldValue;
use App\Models\Team;
use App\Support\TeamDataColumns;
use App\Support\TeamDataCustomFields;
use App\Support\TeamDataIndex;
use App\Support\TeamIdsFilter;
use App\Support\TeamMealCounts;
use App\Support\TeamPhotoCounts;
use App\Support\VolunteerCollectOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventTeamDataController extends Controller
{
    public function index(Event $event): JsonResponse
    {
        return response()->json(TeamDataIndex::payloadForEvent($event));
    }

    public function update(Request $request, Event $event, Team $team): JsonResponse
    {
        if ((int) $team->event !== (int) $event->id) {
            return response()->json(['error' => 'Team gehört nicht zu dieser Veranstaltung.'], 404);
        }

        $collectMeal = VolunteerCollectOptions::collectsMeal($event);
        $customFields = TeamDataColumns::customFieldsForEvent($event->id);
        $fieldsByKey = $customFields->keyBy('field_key');

        if ($request->has('photo_consent')) {
            $validation = TeamPhotoCounts::validateCountMap($request->input('photo_consent'));
            if (! $validation['ok']) {
                return response()->json(['error' => $validation['error']], 422);
            }
            try {
                TeamPhotoCounts::replaceForTeam($team->id, $validation['api']);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['error' => $e->getMessage()], 422);
            }
        }

        if ($request->has('meals')) {
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

            try {
                DB::transaction(function () use ($custom, $fieldsByKey, $team) {
                    foreach ($custom as $fieldKey => $value) {
                        $fieldKey = (string) $fieldKey;
                        /** @var EventTeamField|null $field */
                        $field = $fieldsByKey->get($fieldKey);
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

        return response()->json(TeamDataIndex::teamPayload($event, $team));
    }

    public function exportXlsx(Request $request, Event $event)
    {
        return SpreadsheetResponse::download(
            (new TeamDataSpreadsheetSource(
                $event,
                TeamIdsFilter::parse($request),
            ))->document()
        );
    }
}
