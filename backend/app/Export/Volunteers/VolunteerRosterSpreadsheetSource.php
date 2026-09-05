<?php

namespace App\Export\Volunteers;

use App\Export\Spreadsheet\SpreadsheetColumn;
use App\Export\Spreadsheet\SpreadsheetColumnType;
use App\Export\Spreadsheet\SpreadsheetDocument;
use App\Export\Spreadsheet\SpreadsheetSheet;
use App\Export\Spreadsheet\SpreadsheetSource;
use App\Models\Event;
use App\Models\EventVolunteerField;
use App\Models\EventVolunteerRoster;
use App\Support\StaffingAssignmentLabel;
use App\Support\VolunteerMealOptions;
use App\Support\VolunteerRosterColumns;
use App\Support\VolunteerRosterCustomFields;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class VolunteerRosterSpreadsheetSource implements SpreadsheetSource
{
    /**
     * @param  list<int>|null  $personIds  null = all; empty = none
     */
    public function __construct(
        private readonly Event $event,
        private readonly ?array $personIds = null,
    ) {}

    public function document(): SpreadsheetDocument
    {
        $customFields = VolunteerRosterColumns::customFieldsForEvent($this->event->id);
        $mealOptions = VolunteerMealOptions::optionsForEvent($this->event->id);
        if ($mealOptions->isEmpty()) {
            VolunteerMealOptions::bootstrapForEvent($this->event->id);
            $mealOptions = VolunteerMealOptions::optionsForEvent($this->event->id);
        }
        $mealLabelMap = VolunteerMealOptions::labelMap($mealOptions);
        $definitions = VolunteerRosterColumns::exportDefinitionsForEvent($this->event->id);

        $columns = [];
        foreach ($definitions as $definition) {
            if (! ($definition['export'] ?? false)) {
                continue;
            }
            $columns[] = new SpreadsheetColumn(
                $definition['key'],
                $definition['label'],
                SpreadsheetColumnType::fromDefinition($definition['type'] ?? null),
            );
        }

        $query = EventVolunteerRoster::query()
            ->where('event', $this->event->id)
            ->with(['person', 'detail', 'fieldValues.field']);

        if ($this->personIds !== null) {
            $query->whereIn('volunteer_person', $this->personIds);
        }

        $rosterRows = $query
            ->get()
            ->sortBy(fn (EventVolunteerRoster $row) => [
                mb_strtolower($row->person?->last_name ?? ''),
                mb_strtolower($row->person?->first_name ?? ''),
            ])
            ->values();

        $assignmentsByPerson = StaffingAssignmentLabel::assignmentsByPerson($this->event->id);
        $programNames = $this->programNameMap();

        $rows = [];
        foreach ($rosterRows as $row) {
            if (! $row->person) {
                continue;
            }

            $assignments = $assignmentsByPerson[$row->person->id] ?? [];
            $rows[] = VolunteerRosterColumns::exportValuesForEvent(
                $this->event->id,
                $row,
                $assignments,
                $programNames,
                VolunteerRosterCustomFields::apiValuesForRow($row, $customFields),
                $customFields,
                $mealLabelMap,
            );
        }

        return new SpreadsheetDocument(
            'Helfer:innenliste',
            $this->event->date,
            [
                new SpreadsheetSheet('Helfer:innenliste', $columns, $rows),
            ],
            (string) ($this->event->name ?? ''),
        );
    }

    /**
     * @return array<int, string>
     */
    private function programNameMap(): array
    {
        return DB::table('m_first_program')
            ->pluck('name', 'id')
            ->map(fn ($name) => (string) $name)
            ->all();
    }
}
