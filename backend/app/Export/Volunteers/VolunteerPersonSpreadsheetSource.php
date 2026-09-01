<?php

namespace App\Export\Volunteers;

use App\Export\Spreadsheet\SpreadsheetColumn;
use App\Export\Spreadsheet\SpreadsheetColumnType;
use App\Export\Spreadsheet\SpreadsheetDocument;
use App\Export\Spreadsheet\SpreadsheetSheet;
use App\Export\Spreadsheet\SpreadsheetSource;
use App\Models\Event;
use App\Models\EventVolunteerRoster;
use App\Models\VolunteerPerson;
use App\Support\ContactEmailExportColumns;
use App\Support\SpreadsheetExportVariant;
use App\Support\VolunteerPersonColumns;

final class VolunteerPersonSpreadsheetSource implements SpreadsheetSource
{
    /**
     * @param  list<int>|null  $personIds  null = all in scope; empty = none
     */
    public function __construct(
        private readonly Event $event,
        private readonly string $scope = 'pool',
        private readonly ?array $personIds = null,
        private readonly string $variant = SpreadsheetExportVariant::FULL,
    ) {}

    public function document(): SpreadsheetDocument
    {
        $emailOnly = $this->variant === SpreadsheetExportVariant::EMAIL;

        if ($emailOnly) {
            $columns = ContactEmailExportColumns::spreadsheetColumns();
        } else {
            $columns = [];
            foreach (VolunteerPersonColumns::definitions() as $definition) {
                if (! ($definition['export'] ?? false)) {
                    continue;
                }
                $columns[] = new SpreadsheetColumn(
                    $definition['key'],
                    $definition['label'],
                    SpreadsheetColumnType::fromDefinition($definition['type'] ?? null),
                );
            }
        }

        $query = VolunteerPerson::query()
            ->where('regional_partner', $this->event->regional_partner)
            ->orderBy('last_name')
            ->orderBy('first_name');

        if ($this->scope === 'roster') {
            $query->whereIn('id', EventVolunteerRoster::query()
                ->where('event', $this->event->id)
                ->select('volunteer_person'));
        }

        if ($this->personIds !== null) {
            $query->whereIn('id', $this->personIds);
        }

        $rows = [];
        foreach ($query->get() as $person) {
            if ($emailOnly) {
                $email = trim((string) ($person->email ?? ''));
                if ($email === '') {
                    continue;
                }
                $rows[] = ContactEmailExportColumns::exportValues([
                    'first_name' => $person->first_name,
                    'last_name' => $person->last_name,
                    'email' => $email,
                ]);
                continue;
            }

            $rows[] = VolunteerPersonColumns::exportValues($person);
        }

        $stem = $this->scope === 'roster' ? 'Helfer:innenliste' : 'Personen';
        if ($emailOnly) {
            $stem .= '_email';
        }

        return new SpreadsheetDocument(
            $stem,
            $this->event->date,
            [
                new SpreadsheetSheet($stem, $columns, $rows),
            ],
            (string) ($this->event->name ?? ''),
        );
    }
}
