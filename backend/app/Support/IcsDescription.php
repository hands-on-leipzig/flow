<?php

namespace App\Support;

use DateTimeImmutable;

/**
 * Plain-text ICS DESCRIPTION from the public scheduleInformation JSON.
 * Does not decide publication level: whatever the payload already contains is formatted.
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

    /** @var array<string, string> */
    private const WEEKDAYS_DE = [
        'Mon' => 'Mo',
        'Tue' => 'Di',
        'Wed' => 'Mi',
        'Thu' => 'Do',
        'Fri' => 'Fr',
        'Sat' => 'Sa',
        'Sun' => 'So',
    ];

    /**
     * @param  array<string, mixed>  $payload  scheduleInformation JSON
     */
    public static function fromPublicPayload(array $payload, ?string $planUrl = null): string
    {
        $blocks = [];

        $contact = self::formatContacts($payload['contact'] ?? null);
        if ($contact !== '') {
            $blocks[] = "Kontakt\n".$contact;
        }

        $teams = self::formatTeams($payload['teams'] ?? null);
        if ($teams !== '') {
            $blocks[] = "Angemeldete Teams\n".$teams;
        }

        $plan = $payload['plan'] ?? null;
        if (is_array($plan) && ! isset($plan['error'])) {
            $times = self::formatPlan($plan);
            if ($times !== '') {
                $blocks[] = "Zeitplan\n".$times;
            }
        }

        $level = (int) ($payload['level'] ?? 0);
        if ($level >= 4 && is_string($planUrl) && $planUrl !== '') {
            $blocks[] = 'Vollständiger Zeitplan: '.$planUrl;
        }

        return implode("\n\n", $blocks);
    }

    private static function formatContacts(mixed $contacts): string
    {
        if (! is_array($contacts) || $contacts === []) {
            return '';
        }

        $lines = [];
        foreach ($contacts as $row) {
            if (! is_array($row)) {
                continue;
            }
            $parts = array_filter([
                self::string($row['contact'] ?? null),
                self::string($row['contact_email'] ?? $row['email'] ?? $row['mail'] ?? null),
                self::string($row['contact_infos'] ?? null),
            ], fn ($p) => $p !== '');
            if ($parts !== []) {
                $lines[] = implode(', ', $parts);
            }
        }

        return implode("\n", $lines);
    }

    private static function formatTeams(mixed $teams): string
    {
        if (! is_array($teams) || $teams === []) {
            return '';
        }

        $sections = [];
        foreach ($teams as $key => $group) {
            if (! is_array($group)) {
                continue;
            }
            $list = $group['list'] ?? null;
            if (! is_array($list) || $list === []) {
                continue;
            }
            $heading = self::PLAN_HEADINGS[(string) $key] ?? self::headingFromKey((string) $key);
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
                $sections[] = $heading."\n".implode("\n", $lines);
            }
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param  array<string, mixed>  $team
     */
    private static function formatTeamLine(array $team): string
    {
        $number = self::string($team['team_number_hot'] ?? $team['ref'] ?? null);
        $name = self::string($team['name'] ?? null);
        $org = self::string($team['organization'] ?? null);
        $place = self::string($team['location'] ?? null);
        $parts = array_values(array_filter([$number, $name, $org, $place], fn ($p) => $p !== ''));

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private static function formatPlan(array $plan): string
    {
        $showWeekday = self::planSpansMultipleDays($plan);
        $sections = [];

        foreach (self::PLAN_HEADINGS as $key => $heading) {
            if (! isset($plan[$key]) || ! is_array($plan[$key])) {
                continue;
            }
            $lines = self::formatTimeline($plan[$key], $showWeekday);
            if ($lines !== '') {
                $sections[] = $heading."\n".$lines;
            }
        }

        foreach ($plan as $key => $items) {
            if (isset(self::PLAN_HEADINGS[$key]) || ! is_array($items) || $items === []) {
                continue;
            }
            if (! array_is_list($items) || ! isset($items[0]) || ! is_array($items[0])) {
                continue;
            }
            $lines = self::formatTimeline($items, $showWeekday);
            if ($lines !== '') {
                $sections[] = self::headingFromKey((string) $key)."\n".$lines;
            }
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param  list<mixed>  $items
     */
    private static function formatTimeline(array $items, bool $showWeekday): string
    {
        $rows = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['value'])) {
                continue;
            }
            $time = self::formatTime((string) $item['value'], $showWeekday);
            $label = self::string($item['label'] ?? null);
            $line = trim($time.' '.$label);
            $desc = self::string($item['description'] ?? null);
            if ($desc !== '') {
                $line .= ' — '.$desc;
            }
            if ($line !== '') {
                $rows[] = ['ts' => strtotime((string) $item['value']) ?: 0, 'line' => $line];
            }
        }

        usort($rows, fn ($a, $b) => $a['ts'] <=> $b['ts']);

        return implode("\n", array_column($rows, 'line'));
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private static function planSpansMultipleDays(array $plan): bool
    {
        $dates = [];
        foreach ($plan as $items) {
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (! is_array($item) || empty($item['value'])) {
                    continue;
                }
                $dt = self::parseDate((string) $item['value']);
                if ($dt !== null) {
                    $dates[$dt->format('Y-m-d')] = true;
                }
            }
        }

        return count($dates) > 1;
    }

    private static function formatTime(string $value, bool $showWeekday): string
    {
        $dt = self::parseDate($value);
        if ($dt === null) {
            return '';
        }
        $time = $dt->format('H:i');
        if (! $showWeekday) {
            return $time;
        }
        $wd = self::WEEKDAYS_DE[$dt->format('D')] ?? $dt->format('D');

        return $wd.', '.$time;
    }

    private static function parseDate(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function headingFromKey(string $key): string
    {
        $key = str_replace('_', ' ', $key);

        return $key === '' ? 'Programm' : ucwords($key);
    }

    private static function string(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        return trim((string) $value);
    }
}
