<?php

namespace App\Support;

use App\Models\EventVolunteerMealOption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class VolunteerMealOptions
{
    public const MAX_OPTIONS_PER_EVENT = 20;

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function defaults(): array
    {
        return [
            ['value' => 'standard', 'label' => 'Standard'],
            ['value' => 'vegetarisch', 'label' => 'Vegetarisch'],
            ['value' => 'vegan', 'label' => 'Vegan'],
            ['value' => 'keine', 'label' => 'Keine'],
        ];
    }

    /**
     * @return Collection<int, EventVolunteerMealOption>
     */
    public static function optionsForEvent(int $eventId): Collection
    {
        return EventVolunteerMealOption::query()
            ->where('event', $eventId)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function bootstrapForEvent(int $eventId): array
    {
        $existing = self::optionsForEvent($eventId);
        if ($existing->isNotEmpty()) {
            return self::serializeList($existing);
        }

        foreach (self::defaults() as $index => $option) {
            EventVolunteerMealOption::create([
                'event' => $eventId,
                'value' => $option['value'],
                'label' => $option['label'],
                'sequence' => $index + 1,
            ]);
        }

        return self::serializeList(self::optionsForEvent($eventId));
    }

    /**
     * @param  Collection<int, EventVolunteerMealOption>  $options
     * @return list<array{id: int, value: string, label: string, sequence: int}>
     */
    public static function serializeList(Collection $options): array
    {
        return $options->map(fn (EventVolunteerMealOption $option) => [
            'id' => $option->id,
            'value' => $option->value,
            'label' => $option->label,
            'sequence' => (int) $option->sequence,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, EventVolunteerMealOption>  $options
     * @return array<string, string>
     */
    public static function labelMap(Collection $options): array
    {
        $map = [];
        foreach ($options as $option) {
            $map[$option->value] = $option->label;
        }

        return $map;
    }

    /**
     * @param  Collection<int, EventVolunteerMealOption>  $options
     * @return list<string>
     */
    public static function allowedValues(Collection $options): array
    {
        return $options->pluck('value')->all();
    }

    /**
     * @param  list<array{value?: mixed, label?: mixed}>  $input
     * @return array{ok: true, data: list<array{value: string, label: string}>}|array{ok: false, error: string}
     */
    public static function validateReplaceList(array $input): array
    {
        if ($input === []) {
            return ['ok' => false, 'error' => 'Mindestens eine Essensoption ist erforderlich.'];
        }
        if (count($input) > self::MAX_OPTIONS_PER_EVENT) {
            return ['ok' => false, 'error' => 'Maximal '.self::MAX_OPTIONS_PER_EVENT.' Essensoptionen pro Veranstaltung.'];
        }

        $normalized = [];
        $values = [];
        foreach ($input as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                return ['ok' => false, 'error' => 'Jede Essensoption benötigt eine Bezeichnung.'];
            }
            if (mb_strlen($label) > 120) {
                return ['ok' => false, 'error' => 'Bezeichnungen dürfen maximal 120 Zeichen haben.'];
            }

            $value = trim((string) ($item['value'] ?? ''));
            if ($value === '') {
                $value = Str::slug($label, '_');
                if ($value === '') {
                    $value = 'option';
                }
            }
            if (mb_strlen($value) > 64) {
                return ['ok' => false, 'error' => 'Optionsschlüssel zu lang.'];
            }
            if (in_array($value, $values, true)) {
                return ['ok' => false, 'error' => 'Essensoptionen müssen eindeutige Schlüssel haben.'];
            }

            $values[] = $value;
            $normalized[] = ['value' => $value, 'label' => $label];
        }

        if ($normalized === []) {
            return ['ok' => false, 'error' => 'Mindestens eine Essensoption ist erforderlich.'];
        }

        return ['ok' => true, 'data' => $normalized];
    }

    public static function slugFromLabel(string $label, int $eventId, array $reserved = []): string
    {
        $base = Str::slug($label, '_');
        if ($base === '') {
            $base = 'option';
        }

        $candidate = $base;
        $suffix = 2;
        $existing = array_merge(
            $reserved,
            EventVolunteerMealOption::query()->where('event', $eventId)->pluck('value')->all()
        );
        while (in_array($candidate, $existing, true)) {
            $candidate = $base.'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    public static function usageCount(int $eventId, string $value): int
    {
        return (int) DB::table('event_volunteer_roster_detail as d')
            ->join('event_volunteer_roster as r', 'r.id', '=', 'd.event_volunteer_roster')
            ->where('r.event', $eventId)
            ->where('d.meal', $value)
            ->count();
    }

    /**
     * @param  list<string>  $removedValues
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public static function validateRemovedValues(int $eventId, array $removedValues): array
    {
        foreach ($removedValues as $value) {
            if (self::usageCount($eventId, $value) > 0) {
                return [
                    'ok' => false,
                    'error' => "Essensoption „{$value}“ wird noch von Helfer:innen verwendet und kann nicht entfernt werden.",
                ];
            }
        }

        return ['ok' => true];
    }

    public static function exportMealLabel(?string $meal, array $labelMap): string
    {
        if ($meal === null || $meal === '') {
            return '';
        }

        return $labelMap[$meal] ?? $meal;
    }
}
