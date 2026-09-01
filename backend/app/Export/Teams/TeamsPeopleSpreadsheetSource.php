<?php

namespace App\Export\Teams;

use App\Export\Spreadsheet\SpreadsheetColumn;
use App\Export\Spreadsheet\SpreadsheetColumnType;
use App\Export\Spreadsheet\SpreadsheetDocument;
use App\Export\Spreadsheet\SpreadsheetSheet;
use App\Export\Spreadsheet\SpreadsheetSource;
use App\Http\Controllers\Api\DrahtController;
use App\Models\Event;
use App\Models\Team;
use App\Support\ContactEmailExportColumns;
use App\Support\ProgramCatalog;
use App\Support\SpreadsheetExportVariant;
use App\Support\TeamsPeopleColumns;

final class TeamsPeopleSpreadsheetSource implements SpreadsheetSource
{
    public function __construct(
        private readonly Event $event,
        private readonly DrahtController $drahtController,
        private readonly string $variant = SpreadsheetExportVariant::FULL,
        /** @var list<string>|null */
        private readonly ?array $programSlugs = null,
    ) {}

    public function document(): SpreadsheetDocument
    {
        $emailOnly = $this->variant === SpreadsheetExportVariant::EMAIL;

        if ($emailOnly) {
            $columns = ContactEmailExportColumns::spreadsheetColumns();
        } else {
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
        }

        $rows = [];
        $this->event->loadMissing('programs');

        foreach ($this->event->programs as $program) {
            if (! $this->programMatchesFilter($program)) {
                continue;
            }

            $drahtId = (int) ($program->draht_id ?? 0);
            if ($drahtId <= 0) {
                continue;
            }

            $firstProgramId = (int) ($program->first_program ?? 0);
            $organizationsByTeamNumber = Team::query()
                ->where('event', $this->event->id)
                ->where('first_program', $firstProgramId)
                ->pluck('organization', 'team_number_hot');

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
                $organization = $this->resolveOrganization($teamNumber, $organizationsByTeamNumber, $teamData);

                foreach ($teamData['coaches'] ?? [] as $coach) {
                    if (is_array($coach)) {
                        $firstName = (string) ($coach['firstname'] ?? '');
                        $lastName = (string) ($coach['name'] ?? '');
                        $email = trim((string) ($coach['email'] ?? ''));
                        if ($emailOnly) {
                            if ($email === '') {
                                continue;
                            }
                            $rows[] = ContactEmailExportColumns::exportValues([
                                'first_name' => $firstName,
                                'last_name' => $lastName,
                                'email' => $email,
                            ]);
                            continue;
                        }
                        $rows[] = TeamsPeopleColumns::exportValues([
                            'program' => $programLabel,
                            'team_number' => $teamNumber,
                            'team_name' => $teamName,
                            'role' => 'Coach',
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'gender' => '',
                            'birthday' => '',
                            'email' => $email,
                            'phone' => (string) ($coach['phone'] ?? ''),
                            'organization' => $organization,
                        ]);
                    } elseif (is_string($coach) && trim($coach) !== '' && ! $emailOnly) {
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

                if ($emailOnly) {
                    continue;
                }

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
            }
        }

        $stem = $emailOnly ? 'Teams_email' : 'Teams';

        return new SpreadsheetDocument(
            $stem,
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

    /**
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $organizationsByTeamNumber
     * @param  array<string, mixed>  $teamData
     */
    private function resolveOrganization(string $teamNumber, $organizationsByTeamNumber, array $teamData): string
    {
        $fromFlow = $organizationsByTeamNumber->get((int) $teamNumber)
            ?? $organizationsByTeamNumber->get($teamNumber);

        if (is_string($fromFlow) && trim($fromFlow) !== '') {
            return trim($fromFlow);
        }

        $fromDraht = $teamData['organization'] ?? '';

        return is_string($fromDraht) ? trim($fromDraht) : '';
    }

    private function programMatchesFilter(object $program): bool
    {
        if ($this->programSlugs === null) {
            return true;
        }
        if ($this->programSlugs === []) {
            return false;
        }

        $firstProgramId = (int) ($program->first_program ?? 0);
        foreach ($this->programSlugs as $slug) {
            $resolved = ProgramCatalog::resolve($slug);
            if ($resolved && $firstProgramId === (int) $resolved->id) {
                return true;
            }
        }

        return false;
    }
}
