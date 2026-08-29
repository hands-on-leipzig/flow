<?php

namespace App\Support;

/**
 * Plain-text ICS DESCRIPTION. Always the public basis block; publication
 * level is ignored. Times and the interactive plan stay behind Zeitplan: URL.
 */
final class IcsDescription
{
    /** @var array<string, string> */
    private const PLAN_HEADINGS = [
        'explore' => 'Explore',
        'explore_morning' => 'Explore Vormittag',
        'explore_afternoon' => 'Explore Nachmittag',
        'challenge' => 'Challenge',
    ];

    /**
     * @param  array<string, mixed>  $payload  scheduleInformation JSON
     * @param  list<string>  $programNames  m_first_program.display_name at the event, catalog order
     */
    public static function fromPublicPayload(array $payload, ?string $planUrl = null, array $programNames = []): string
    {
        $header = [];

        $programs = self::formatProgramNames($programNames);
        if ($programs !== '') {
            $header[] = 'Programme: '.$programs;
        }

        $contact = self::formatContacts($payload['contact'] ?? null);
        if ($contact !== '') {
            $header[] = 'Kontakt: '.$contact;
        }

        $blocks = [];
        if ($header !== []) {
            $blocks[] = implode("\n", $header);
        }

        $teams = self::formatTeams($payload['teams'] ?? null);
        if ($teams !== '') {
            $blocks[] = "Angemeldete Teams\n".$teams;
        }

        if (is_string($planUrl) && $planUrl !== '') {
            $blocks[] = 'Zeitplan: '.$planUrl;
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param  list<mixed>  $names
     */
    private static function formatProgramNames(array $names): string
    {
        $clean = [];
        foreach ($names as $name) {
            $label = self::oneLine($name);
            if ($label !== '') {
                $clean[] = $label;
            }
        }

        return implode(', ', $clean);
    }

    private static function formatContacts(mixed $contacts): string
    {
        if (! is_array($contacts) || $contacts === []) {
            return '';
        }

        foreach ($contacts as $row) {
            if (! is_array($row)) {
                continue;
            }
            $parts = array_filter([
                self::oneLine($row['contact'] ?? null),
                self::oneLine($row['contact_email'] ?? $row['email'] ?? $row['mail'] ?? null),
                self::oneLine($row['contact_infos'] ?? null),
            ], fn ($p) => $p !== '');
            if ($parts !== []) {
                return implode(', ', $parts);
            }
        }

        return '';
    }

    private static function formatTeams(mixed $teams): string
    {
        if (! is_array($teams) || $teams === []) {
            return '';
        }

        if (isset($teams['lanes']) && is_array($teams['lanes'])) {
            return self::formatTeamSections($teams['lanes'], true);
        }

        $legacy = [];
        foreach ($teams as $key => $group) {
            if (! is_array($group)) {
                continue;
            }
            $list = $group['list'] ?? null;
            if (! is_array($list) || $list === []) {
                continue;
            }
            $legacy[] = [
                'name' => self::PLAN_HEADINGS[(string) $key] ?? self::headingFromKey((string) $key),
                'teams' => $list,
            ];
        }

        return self::formatTeamSections($legacy, false);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private static function formatTeamSections(array $sections, bool $useLaneTeamsKey): string
    {
        $blocks = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $list = $useLaneTeamsKey ? ($section['teams'] ?? null) : ($section['teams'] ?? $section['list'] ?? null);
            if (! is_array($list) || $list === []) {
                continue;
            }
            $heading = self::oneLine($section['name'] ?? null);
            if ($heading === '') {
                $heading = 'Programm';
            }
            $lines = [];
            foreach ($list as $team) {
                if (! is_array($team)) {
                    continue;
                }
                $line = self::formatTeamLine($team);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
            if ($lines !== []) {
                $blocks[] = $heading."\n".implode("\n", $lines);
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param  array<string, mixed>  $team
     */
    private static function formatTeamLine(array $team): string
    {
        $number = self::string($team['ref'] ?? $team['team_number_hot'] ?? null);
        $name = self::string($team['name'] ?? null);
        $org = self::string($team['organization'] ?? null);
        $place = self::string($team['location'] ?? null);
        $parts = array_values(array_filter([$number, $name, $org, $place], fn ($p) => $p !== ''));

        return implode(' · ', $parts);
    }

    private static function headingFromKey(string $key): string
    {
        $key = str_replace('_', ' ', $key);

        return $key === '' ? 'Programm' : ucwords($key);
    }

    private static function oneLine(mixed $value): string
    {
        $text = self::string($value);
        if ($text === '') {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function string(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        return trim((string) $value);
    }
}
