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

        $assignmentsByPerson = $this->assignmentsByPerson($this->event->id);
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
                $this->customValuesForRow($row, $customFields),
                $customFields,
            );
        }

        return new SpreadsheetDocument(
            'Helferliste',
            $this->event->date,
            [
                new SpreadsheetSheet('Helferliste', $columns, $rows),
            ],
            (string) ($this->event->name ?? ''),
        );
    }

    /**
     * @param  Collection<int, EventVolunteerField>  $customFields
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

    /**
     * Mirrors EventVolunteerRosterController::assignmentsByPerson export needs.
     *
     * @return array<int, list<array{tile_name: string, label: string, role_id: int, first_program: ?int, is_local: bool, sequence: int, group_index: int}>>
     */
    private function assignmentsByPerson(int $eventId): array
    {
        $groupCounts = DB::table('event_staffing_group as g')
            ->join('event_staffing_role as r', 'r.id', '=', 'g.event_staffing_role')
            ->where('r.event', $eventId)
            ->groupBy('g.event_staffing_role')
            ->pluck(DB::raw('count(*)'), 'g.event_staffing_role');

        $rows = DB::table('event_staffing_assignment as a')
            ->join('event_staffing_group as g', 'g.id', '=', 'a.event_staffing_group')
            ->join('event_staffing_role as r', 'r.id', '=', 'g.event_staffing_role')
            ->leftJoin('m_role as mr', 'mr.id', '=', 'r.m_role')
            ->where('r.event', $eventId)
            ->orderBy('r.sequence')
            ->orderBy('r.id')
            ->orderBy('g.group_index')
            ->get([
                'a.volunteer_person',
                'r.id as role_id',
                'r.label as role_label',
                'r.sequence',
                'r.m_role',
                'mr.name as catalog_name',
                'mr.first_program',
                'g.group_index',
                'g.surplus',
            ]);

        $assignmentsByPerson = [];
        foreach ($rows as $row) {
            $personId = (int) $row->volunteer_person;
            $roleLabel = trim((string) ($row->role_label ?: ($row->catalog_name ?: 'Rolle')));
            $groupCount = (int) ($groupCounts[$row->role_id] ?? 1);
            $tileName = ($groupCount <= 1 && ! $row->surplus)
                ? $roleLabel
                : trim($roleLabel.' '.$row->group_index);

            $assignment = [
                'tile_name' => $tileName,
                'label' => $roleLabel,
                'role_id' => (int) $row->role_id,
                'first_program' => $row->m_role ? (($row->first_program !== null) ? (int) $row->first_program : null) : null,
                'is_local' => $row->m_role === null,
                'sequence' => (int) $row->sequence,
                'group_index' => (int) $row->group_index,
            ];

            if (! isset($assignmentsByPerson[$personId])) {
                $assignmentsByPerson[$personId] = [];
            }

            $exists = false;
            foreach ($assignmentsByPerson[$personId] as $existing) {
                if ($existing['tile_name'] === $assignment['tile_name']) {
                    $exists = true;
                    break;
                }
            }
            if (! $exists) {
                $assignmentsByPerson[$personId][] = $assignment;
            }
        }

        return $assignmentsByPerson;
    }
}
