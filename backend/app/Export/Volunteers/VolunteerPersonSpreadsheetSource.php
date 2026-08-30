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
    ) {}

    public function document(): SpreadsheetDocument
    {
        $definitions = VolunteerPersonColumns::definitions();
        $columns = [];
        foreach ($definitions as $definition) {
            if (! ($definition['export'] ?? false)) {
                continue;
            }
            $columns[] = new SpreadsheetColumn(
                $definition['key'],
                $definition['label'],
                SpreadsheetColumnType::String,
            );
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
            $rows[] = VolunteerPersonColumns::exportValues($person);
        }

        $stem = $this->scope === 'roster' ? 'Helferliste' : 'Personen';

        return new SpreadsheetDocument(
            $stem,
            $this->event->date,
            [
                new SpreadsheetSheet($stem, $columns, $rows),
            ],
        );
    }
}
