<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class PlanParameter
{
    private array $params = [];
    private static ?self $instance = null;

    public static function load(int $planId): void
    {
        self::$instance = new self($planId);
    }

    public static function get(string $key): mixed
    {
        if (!self::$instance) {
            throw new \RuntimeException("PlanParameter not loaded.");
        }

        return self::$instance->getValue($key);
    }

    public static function add(string $key, mixed $value, string $type = 'string'): void
    {
        if (!self::$instance) {
            throw new \RuntimeException("PlanParameter not loaded.");
        }

        self::$instance->addInternal($key, $value, $type);
    }

    private function __construct(private readonly int $planId)
    {
        $this->init();
    }

    private function getValue(string $key): mixed
    {
        if (!array_key_exists($key, $this->params)) {
            throw new \RuntimeException("Parameter '{$key}' not found.");
        }

        return $this->params[$key]['value'];
    }

    private function addInternal(string $key, mixed $value, string $type): void
    {
        $this->params[$key] = [
            'value' => $this->cast($value, $type),
            'type' => $type,
        ];
    }

    private function localTimeToUtc(string $hhmm, string $dateYmd): string
    {
        // z.B. $hhmm = "09:00", $dateYmd = "2026-01-16"
        $dt = \Carbon\Carbon::createFromFormat('Y-m-d H:i', "{$dateYmd} {$hhmm}", 'Europe/Berlin');
        return $dt->utc()->format('H:i'); // nur Uhrzeit in UTC zurückgeben
    }

    private function init(): void
    {
        $this->addInternal('g_plan', $this->planId, 'integer');

        $eventId = DB::table('plan')
            ->where('id', $this->planId)
            ->value('event');

        if (!$eventId) {
            throw new \RuntimeException("Kein Event zur Plan-ID {$this->planId} gefunden.");
        }

        $event = DB::table('event')
            ->select('level', 'date', 'days')
            ->where('id', $eventId)
            ->first();

        if (!$event) {
            throw new \RuntimeException("Event-ID {$eventId} nicht gefunden.");
        }

        $this->addInternal('g_level', $event->level, 'integer');
        $this->addInternal('g_date', $event->date, 'date');
        $this->addInternal('g_days', $event->days, 'integer');
        $this->addInternal('g_finale', ((int)$event->level === 3), 'boolean');

        $base = DB::table('m_parameter')
            ->select('id', 'name', 'type', 'value')
            ->get()
            ->keyBy('id');

        $overrides = DB::table('plan_param_value')
            ->select('parameter', 'set_value')
            ->where('plan', $this->planId)
            ->get()
            ->keyBy('parameter');

        foreach ($base as $id => $row) {
            // 1) Rohwert ermitteln (Override vor Default)
            $raw = $overrides->has($id)
                ? $overrides[$id]->set_value
                : $row->value;

            // 2) Falls Typ 'time' → lokale (Europe/Berlin) Uhrzeit am Event-Datum in UTC umrechnen
            if ($row->type === 'time' && $raw !== null && $raw !== '') {
                // $event->date ist oben bereits geladen (YYYY-MM-DD)
                $raw = $this->localTimeToUtc((string) $raw, (string) $event->date);
            }

            // 3) Cast und in Params ablegen
            $this->params[$row->name] = [
                'value' => $this->cast($raw, $row->type),
                'type'  => $row->type,
            ];
        }

    }

    private function cast(mixed $value, ?string $type): mixed
    {
        if ($value === null) return null;

        return match ($type) {
            'integer' => (int)$value,
            'decimal' => (float)$value,
            'boolean' => $value == '1',
            'time', 'date' => $value,
            default => (string)$value,
        };
    }
}

?>