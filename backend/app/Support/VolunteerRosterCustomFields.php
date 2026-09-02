<?php

namespace App\Support;

use App\Models\EventVolunteerField;
use App\Models\EventVolunteerRoster;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class VolunteerRosterCustomFields
{
    public const MAX_FIELDS_PER_EVENT = 15;

    public const TYPES = ['text', 'number', 'boolean', 'select'];

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string}
     */
    public static function validateDefinition(array $input, ?EventVolunteerField $existing = null): array
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
            $normalized = self::normalizeOptions(is_array($rawOptions) ? $rawOptions : []);
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

    /**
     * @param  list<array{value?: mixed, label?: mixed}|string>  $raw
     * @return list<array{value: string, label: string}>
     */
    public static function normalizeOptions(array $raw): array
    {
        $options = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $value = trim($item);
                if ($value === '') {
                    continue;
                }
                $options[] = ['value' => $value, 'label' => $value];
                continue;
            }
            if (! is_array($item)) {
                continue;
            }
            $value = trim((string) ($item['value'] ?? ''));
            $label = trim((string) ($item['label'] ?? $value));
            if ($value === '' || $label === '') {
                continue;
            }
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    public static function slugFromLabel(string $label, int $eventId): string
    {
        $base = Str::slug($label, '_');
        if ($base === '') {
            $base = 'feld';
        }

        $candidate = $base;
        $suffix = 2;
        while (EventVolunteerField::query()
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
    public static function serializeField(EventVolunteerField $field): array
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
     * @return array<string, mixed>
     */
    public static function serializeColumn(EventVolunteerField $field): array
    {
        return [
            'key' => 'custom:'.$field->field_key,
            'label' => $field->label,
            'sortable' => false,
            'kind' => 'custom',
            'type' => $field->type,
            'field_key' => $field->field_key,
            'options' => $field->type === 'select' ? ($field->options ?? []) : [],
            'public_form' => (bool) ($field->public_form ?? false),
        ];
    }

    /**
     * @param  mixed  $input
     * @return array{ok: true, stored: ?string, api: mixed}|array{ok: false, error: string}
     */
    public static function validateValue(EventVolunteerField $field, mixed $input): array
    {
        if ($input === null || $input === '') {
            return ['ok' => true, 'stored' => null, 'api' => null];
        }

        return match ($field->type) {
            'text' => self::validateTextValue($input),
            'number' => self::validateNumberValue($input),
            'boolean' => self::validateBooleanValue($input),
            'select' => self::validateSelectValue($field, $input),
            default => ['ok' => false, 'error' => 'Ungültiger Feldtyp.'],
        };
    }

    /**
     * @return array{ok: true, stored: ?string, api: mixed}|array{ok: false, error: string}
     */
    private static function validateTextValue(mixed $input): array
    {
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

        $normalized = rtrim(rtrim(number_format((float) $input, 6, '.', ''), '0'), '.');

        return ['ok' => true, 'stored' => $normalized, 'api' => $normalized === '' ? '0' : $normalized];
    }

    /**
     * @return array{ok: true, stored: ?string, api: mixed}|array{ok: false, error: string}
     */
    private static function validateBooleanValue(mixed $input): array
    {
        if ($input === null || $input === '') {
            return ['ok' => true, 'stored' => null, 'api' => null];
        }

        $bool = filter_var($input, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === null && ! in_array($input, [0, 1, '0', '1', false, true], true)) {
            return ['ok' => false, 'error' => 'Ungültiger Ja/Nein-Wert.'];
        }

        $bool = (bool) $bool;

        return ['ok' => true, 'stored' => $bool ? '1' : '0', 'api' => $bool];
    }

    /**
     * @return array{ok: true, stored: ?string, api: mixed}|array{ok: false, error: string}
     */
    private static function validateSelectValue(EventVolunteerField $field, mixed $input): array
    {
        $value = trim((string) $input);
        if ($value === '') {
            return ['ok' => true, 'stored' => null, 'api' => null];
        }

        $allowed = array_column($field->options ?? [], 'value');
        if (! in_array($value, $allowed, true)) {
            return ['ok' => false, 'error' => 'Ungültige Auswahl.'];
        }

        return ['ok' => true, 'stored' => $value, 'api' => $value];
    }

    public static function apiValue(EventVolunteerField $field, ?string $stored): mixed
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        return match ($field->type) {
            'boolean' => $stored === '1',
            'number' => $stored,
            default => $stored,
        };
    }

    public static function exportValue(EventVolunteerField $field, ?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        if ($field->type === 'boolean') {
            return $stored === '1' ? 'ja' : 'nein';
        }

        if ($field->type === 'select') {
            foreach ($field->options ?? [] as $option) {
                if (($option['value'] ?? '') === $stored) {
                    return (string) ($option['label'] ?? $stored);
                }
            }
        }

        return $stored;
    }

    public static function isUnset(EventVolunteerField $field, mixed $apiValue): bool
    {
        if ($apiValue === null) {
            return true;
        }

        if ($field->type === 'text' && trim((string) $apiValue) === '') {
            return true;
        }

        return false;
    }

    /**
     * @param  Collection<int, EventVolunteerField>  $customFields
     * @return array<string, mixed>
     */
    public static function apiValuesForRow(EventVolunteerRoster $row, Collection $customFields): array
    {
        $valuesByFieldId = $row->fieldValues->keyBy('event_volunteer_field');
        $payload = [];

        foreach ($customFields as $field) {
            $stored = $valuesByFieldId->get($field->id)?->value;
            $payload[$field->field_key] = self::apiValue($field, $stored);
        }

        return $payload;
    }
}
