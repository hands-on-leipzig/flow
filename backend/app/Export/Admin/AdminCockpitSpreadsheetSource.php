<?php

namespace App\Export\Admin;

use App\Export\Spreadsheet\SpreadsheetColumn;
use App\Export\Spreadsheet\SpreadsheetColumnType;
use App\Export\Spreadsheet\SpreadsheetDocument;
use App\Export\Spreadsheet\SpreadsheetSheet;
use App\Export\Spreadsheet\SpreadsheetSource;
use DateTimeInterface;

final class AdminCockpitSpreadsheetSource implements SpreadsheetSource
{
    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(
        private readonly array $events,
        private readonly DateTimeInterface|string|null $date = null,
    ) {}

    public function document(): SpreadsheetDocument
    {
        $columns = [
            new SpreadsheetColumn('rp', 'RP', SpreadsheetColumnType::Number),
            new SpreadsheetColumn('date', 'Datum', SpreadsheetColumnType::Date),
            new SpreadsheetColumn('event', 'Event'),
            new SpreadsheetColumn('e', 'E'),
            new SpreadsheetColumn('c', 'C'),
            new SpreadsheetColumn('f8', 'F8'),
            new SpreadsheetColumn('plan', 'Ablauf'),
            new SpreadsheetColumn('teams', 'Teams'),
            new SpreadsheetColumn('rooms', 'Räume'),
            new SpreadsheetColumn('staffing', 'Zuordnung'),
            new SpreadsheetColumn('generator', 'Letzter Generatorlauf'),
            new SpreadsheetColumn('params', 'Veränderte Parameter'),
            new SpreadsheetColumn('blocks', 'Extra-Blöcke'),
            new SpreadsheetColumn('helferliste', 'Helferliste', SpreadsheetColumnType::Number),
            new SpreadsheetColumn('publish', 'Veröffentlichung'),
        ];

        $rows = [];
        foreach ($this->events as $event) {
            $params = $event['param_changes'] ?? null;
            $paramCell = is_array($params)
                ? ((int) ($params['input'] ?? 0)).' + '.((int) ($params['expert'] ?? 0))
                : '';
            $rows[] = [
                $event['regional_partner_id'] ?? '',
                $event['event_date'] ?? '',
                $event['event_name'] ?? '',
                $this->teamCell($event, 2),
                $this->teamCell($event, 3),
                $this->teamCell($event, 8),
                $this->liveDot($event['dots']['plan'] ?? null),
                '—',
                $this->liveDot($event['dots']['rooms'] ?? null),
                $this->liveDot($event['dots']['staffing'] ?? null),
                $event['generator_last_end'] ?? '',
                $paramCell,
                $event['extra_blocks_free'] === null ? '' : (int) $event['extra_blocks_free'],
                (int) ($event['helferliste_count'] ?? 0),
                $event['publication_level'] === null ? '' : (int) $event['publication_level'],
            ];
        }

        return new SpreadsheetDocument(
            'Cockpit',
            $this->date,
            [new SpreadsheetSheet('Cockpit', $columns, $rows)],
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function teamCell(array $event, int $programId): string
    {
        $teams = $event['teams'] ?? [];
        $value = $teams[(string) $programId] ?? null;
        if ($value === null) {
            return '';
        }

        return (string) ((int) $value);
    }

    private function liveDot(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return $value ? 'ja' : 'nein';
    }
}
