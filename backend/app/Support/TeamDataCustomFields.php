<?php

namespace App\Support;

use App\Models\EventTeamField;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class TeamDataCustomFields
{
    public const MAX_FIELDS_PER_EVENT = 15;

    public const TYPES = ['text', 'number', 'boolean', 'select'];

    public const BOOLEAN_KEYS = ['unknown', 'yes', 'no'];

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string}
     */
    public static function validateDefinition(array $input, ?EventTeamField $existing = null): array
    {
        $label = array_key_exists('label', $input)
            ? trim((string) $input['label'])
            : ($existing?->label ?? '');

        if ($label === '') {
            return ['ok' => false, 'error' => 'Bezeichnung ist erforderlich.'];
        }
        if (mb_strlen($label) > 120) {
            return ['ok' => false, 'error' => 'Bezeichnung darf maximal 120 Zeichen haben.'];
        }

        $type = array_key_exists('type', $input)
            ? trim((string) $input['type'])
            : ($existing?->type ?? '');

        if (! in_array($type, self::TYPES, true)) {
            return ['ok' => false, 'error' => 'Ungültiger Feldtyp.'];
        }

        $options = null;
        if ($type === 'select') {
            $rawOptions = $input['options'] ?? $existing?->options ?? [];
            $normalized = VolunteerRosterCustomFields::normalizeOptions(is_array($rawOptions) ? $rawOptions : []);
            if ($normalized === []) {
                return ['ok' => false, 'error' => 'Auswahl-Felder benötigen mindestens eine Option.'];
            }
            $options = $normalized;
        }

        return [
            'ok' => true,
            'data' => [
                'label' => $label,
                'type' => $type,
                'options' => $options,
            ],
        ];
    }

    public static function slugFromLabel(string $label, int $eventId): string
    {
        $base = Str::slug($label, '_');
        if ($base === '') {
            $base = 'feld';
        }

        $candidate = $base;
        $suffix = 2;
        while (EventTeamField::query()
            ->where('event', $eventId)
            ->where('field_key', $candidate)
            ->exists()) {
            $candidate = $base.'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeField(EventTeamField $field): array
    {
        return [
            'id' => $field->id,
            'field_key' => $field->field_key,
            'label' => $field->label,
            'type' => $field->type,
            'options' => $field->type === 'select' ? ($field->options ?? []) : [],
            'sequence' => (int) $field->sequence,
            'public_form' => (bool) ($field->public_form ?? false),
        ];
    }

    /**
     * @param  mixed  $input
     * @return array{ok: true, stored: ?string, api: mixed}|array{ok: false, error: string}
     */
    public static function validateValue(EventTeamField $field, mixed $input): array
    {
        if ($input === null) {
            return ['ok' => true, 'stored' => null, 'api' => null];
        }

        return match ($field->type) {
            'text' => self::validateTextValue($input),
            'number' => self::validateNumberValue($input),
            'boolean' => self::validateBooleanCountMap($input),
            'select' => self::validateSelectCountMap($field, $input),
            default => ['ok' => false, 'error' => 'Ungültiger Feldtyp.'],
        };
    }

    /**
     * @return array{ok: true, stored: ?string, api: mixed}|array{ok: false, error: string}
     */
    private static function validateTextValue(mixed $input): array
    {
        if (! is_string($input) && ! is_numeric($input)) {
            return ['ok' => false, 'error' => 'Ungültiger Text.'];
        }
        $value = trim((string) $input);
        if ($value === '') {
            return ['ok' => true, 'stored' => null, 'api' => null];
        }
        if (mb_strlen($value) > 1000) {
            return ['ok' => false, 'error' => 'Text darf maximal 1000 Zeichen haben.'];
        }

        return ['ok' => true, 'stored' => $value, 'api' => $value];
    }

    /**
     * @return array{ok: true, stored: ?string, api: mixed}|array{ok: false, error: string}
     */
    private static function validateNumberValue(mixed $input): array
    {
        if (is_string($input)) {
            $input = trim($input);
            if ($input === '') {
                return ['ok' => true, 'stored' => null, 'api' => null];
            }
        }

        if (! is_numeric($input)) {
            return ['ok' => false, 'error' => 'Ungültige Zahl.'];
        }

        $intVal = (int) $input;
        if ($intVal < 0) {
            return ['ok' => false, 'error' => 'Zahl muss ≥ 0 sein.'];
        }

        $stored = (string) $intVal;

        return ['ok' => true, 'stored' => $stored, 'api' => $intVal];
    }

    /**
     * @return array{ok: true, stored: ?string, api: mixed}|array{ok: false, error: string}
     */
    private static function validateBooleanCountMap(mixed $input): array
    {
        if (! is_array($input)) {
            return ['ok' => false, 'error' => 'Ungültige Anzahlen.'];
        }

        $normalized = [];
        foreach (self::BOOLEAN_KEYS as $key) {
            if (! array_key_exists($key, $input)) {
                return ['ok' => false, 'error' => 'Alle Anzahlen (? / Ja / Nein) sind erforderlich.'];
            }
            $count = self::parseNonNegativeInt($input[$key]);
            if ($count === null) {
                return ['ok' => false, 'error' => 'Ungültige Anzahl für '.$key.'.'];
            }
            $normalized[$key] = $count;
        }

        $stored = json_encode($normalized, JSON_THROW_ON_ERROR);

        return ['ok' => true, 'stored' => $stored, 'api' => $normalized];
    }

    /**
     * @return array{ok: true, stored: ?string, api: mixed}|array{ok: false, error: string}
     */
    private static function validateSelectCountMap(EventTeamField $field, mixed $input): array
    {
        if (! is_array($input)) {
            return ['ok' => false, 'error' => 'Ungültige Anzahlen.'];
        }

        $options = $field->options ?? [];
        if ($options === []) {
            return ['ok' => false, 'error' => 'Auswahl-Feld ohne Optionen.'];
        }

        $normalized = [];
        foreach ($options as $option) {
            $value = (string) ($option['value'] ?? '');
            if ($value === '') {
                continue;
            }
            if (! array_key_exists($value, $input)) {
                return ['ok' => false, 'error' => 'Anzahl für jede Option ist erforderlich.'];
            }
            $count = self::parseNonNegativeInt($input[$value]);
            if ($count === null) {
                return ['ok' => false, 'error' => 'Ungültige Anzahl für '.$value.'.'];
            }
            $normalized[$value] = $count;
        }

        $stored = json_encode($normalized, JSON_THROW_ON_ERROR);

        return ['ok' => true, 'stored' => $stored, 'api' => $normalized];
    }

    private static function parseNonNegativeInt(mixed $input): ?int
    {
        if (is_string($input)) {
            $input = trim($input);
            if ($input === '') {
                return null;
            }
        }
        if (! is_numeric($input)) {
            return null;
        }
        $intVal = (int) $input;
        if ($intVal < 0) {
            return null;
        }

        return $intVal;
    }

    /**
     * @return array<string, int>|string|int|null
     */
    public static function apiValue(EventTeamField $field, ?string $stored): mixed
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        if ($field->type === 'boolean' || $field->type === 'select') {
            $decoded = json_decode($stored, true);
            if (! is_array($decoded)) {
                return null;
            }

            return array_map(fn ($v) => (int) $v, $decoded);
        }

        if ($field->type === 'number') {
            return (int) $stored;
        }

        return $stored;
    }

    /**
     * @param  array<string, int>  $map
     */
    public static function sumCountMap(array $map): int
    {
        return array_sum(array_map('intval', $map));
    }

    /**
     * @param  Collection<int, EventTeamField>  $fields
     * @param  Collection<int, \App\Models\EventTeamFieldValue>  $values
     * @return array<string, mixed>
     */
    public static function apiValuesForTeam(Collection $fields, Collection $values): array
    {
        $valuesByFieldId = $values->keyBy('event_team_field');
        $payload = [];

        foreach ($fields as $field) {
            $stored = $valuesByFieldId->get($field->id)?->value;
            $payload[$field->field_key] = self::apiValue($field, $stored);
        }

        return $payload;
    }
}
