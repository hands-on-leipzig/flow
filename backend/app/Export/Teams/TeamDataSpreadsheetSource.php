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
    /**
     * @param  list<int>|null  $teamIds  null = all; empty = none
     */
    public function __construct(
        private readonly Event $event,
        private readonly ?array $teamIds = null,
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

        if ($this->teamIds !== null) {
            $allowed = array_fill_keys($this->teamIds, true);
            $teams = array_values(array_filter(
                $teams,
                static fn (array $team): bool => isset($allowed[(int) ($team['id'] ?? 0)]),
            ));
        }

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
