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

    private function init(): void
    {
        $this->addInternal('g_plan', $this->planId, 'integer');

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
            $value = $overrides->has($id)
                ? $overrides[$id]->set_value
                : $row->value;

            $this->params[$row->name] = [
                'value' => $this->cast($value, $row->type),
                'type' => $row->type,
            ];
        }

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