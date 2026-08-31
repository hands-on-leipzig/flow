<?php

namespace App\Export\Teams;

use App\Export\Spreadsheet\SpreadsheetColumn;
use App\Export\Spreadsheet\SpreadsheetColumnType;
use App\Export\Spreadsheet\SpreadsheetDocument;
use App\Export\Spreadsheet\SpreadsheetSheet;
use App\Export\Spreadsheet\SpreadsheetSource;
use App\Http\Controllers\Api\DrahtController;
use App\Models\Event;
use App\Support\TeamsPeopleColumns;

final class TeamsPeopleSpreadsheetSource implements SpreadsheetSource
{
    public function __construct(
        private readonly Event $event,
        private readonly DrahtController $drahtController,
    ) {}

    public function document(): SpreadsheetDocument
    {
        $columns = [];
        foreach (TeamsPeopleColumns::definitions() as $definition) {
            if (! ($definition['export'] ?? false)) {
                continue;
            }
            $columns[] = new SpreadsheetColumn(
                $definition['key'],
                $definition['label'],
                SpreadsheetColumnType::fromDefinition($definition['type'] ?? null),
            );
        }

        $rows = [];
        $this->event->loadMissing('programs');

        foreach ($this->event->programs as $program) {
            $drahtId = (int) ($program->draht_id ?? 0);
            if ($drahtId <= 0) {
                continue;
            }

            $programLabel = (string) ($program->display_name ?? $program->name ?? '');
            $peopleData = $this->fetchPeople($drahtId);
            if ($peopleData === null) {
                continue;
            }

            unset($peopleData['total_players'], $peopleData['total_coaches']);

            foreach ($peopleData as $teamKey => $teamData) {
                if (! is_array($teamData)) {
                    continue;
                }

                $teamNumber = is_numeric($teamKey) ? (string) $teamKey : (string) ($teamData['number'] ?? $teamKey);
                $teamName = (string) ($teamData['name'] ?? '');
                $organization = (string) ($teamData['organization'] ?? '');

                foreach ($teamData['players'] ?? [] as $player) {
                    if (! is_array($player)) {
                        continue;
                    }
                    $rows[] = TeamsPeopleColumns::exportValues([
                        'program' => $programLabel,
                        'team_number' => $teamNumber,
                        'team_name' => $teamName,
                        'role' => 'Teammitglied',
                        'first_name' => (string) ($player['firstname'] ?? ''),
                        'last_name' => (string) ($player['name'] ?? ''),
                        'gender' => (string) ($player['gender'] ?? ''),
                        'birthday' => TeamsPeopleColumns::formatBirthday($player['birthday'] ?? null),
                        'email' => '',
                        'phone' => '',
                        'organization' => $organization,
                    ]);
                }

                foreach ($teamData['coaches'] ?? [] as $coach) {
                    if (is_array($coach)) {
                        $rows[] = TeamsPeopleColumns::exportValues([
                            'program' => $programLabel,
                            'team_number' => $teamNumber,
                            'team_name' => $teamName,
                            'role' => 'Coach',
                            'first_name' => (string) ($coach['firstname'] ?? ''),
                            'last_name' => (string) ($coach['name'] ?? ''),
                            'gender' => '',
                            'birthday' => '',
                            'email' => (string) ($coach['email'] ?? ''),
                            'phone' => (string) ($coach['phone'] ?? ''),
                            'organization' => $organization,
                        ]);
                    } elseif (is_string($coach) && trim($coach) !== '') {
                        $rows[] = TeamsPeopleColumns::exportValues([
                            'program' => $programLabel,
                            'team_number' => $teamNumber,
                            'team_name' => $teamName,
                            'role' => 'Coach',
                            'first_name' => '',
                            'last_name' => trim($coach),
                            'gender' => '',
                            'birthday' => '',
                            'email' => '',
                            'phone' => '',
                            'organization' => $organization,
                        ]);
                    }
                }
            }
        }

        return new SpreadsheetDocument(
            'Teams',
            $this->event->date,
            [
                new SpreadsheetSheet('Teams', $columns, $rows),
            ],
            (string) ($this->event->name ?? ''),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPeople(int $drahtEventId): ?array
    {
        $response = $this->drahtController->getPeople($drahtEventId);
        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->getData(true);

        return is_array($data) ? $data : null;
    }
}
