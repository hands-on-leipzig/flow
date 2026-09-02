<?php

namespace App\Export\Teams;

use App\Export\Spreadsheet\SpreadsheetColumn;
use App\Export\Spreadsheet\SpreadsheetColumnType;
use App\Export\Spreadsheet\SpreadsheetDocument;
use App\Export\Spreadsheet\SpreadsheetSheet;
use App\Export\Spreadsheet\SpreadsheetSource;
use App\Models\Event;
use App\Support\TeamDataColumns;
use App\Support\TeamDataIndex;

final class TeamDataSpreadsheetSource implements SpreadsheetSource
{
    public function __construct(
        private readonly Event $event,
    ) {}

    public function document(): SpreadsheetDocument
    {
        $definitions = TeamDataColumns::exportDefinitionsForEvent($this->event->id);
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

        $payload = TeamDataIndex::payloadForEvent($this->event);
        $teams = is_array($payload['teams'] ?? null) ? $payload['teams'] : [];

        $rows = [];
        foreach ($teams as $team) {
            $rows[] = TeamDataColumns::exportRowValues($definitions, $team);
        }

        return new SpreadsheetDocument(
            'Teamdaten',
            $this->event->date,
            [
                new SpreadsheetSheet('Teamdaten', $columns, $rows),
            ],
            (string) ($this->event->name ?? ''),
        );
    }
}
