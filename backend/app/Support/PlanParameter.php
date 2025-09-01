<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class PlanParameter
{
    private array $params = [];

    public function __construct(private readonly int $planId)
    {
        $this->load();
    }

    private function load(): void
    {
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

        $this->load();

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

        $this->add('g_level', $event->level, 'integer');
        $this->add('g_date', $event->date, 'date');
        $this->add('g_days', $event->days, 'integer');
        $this->add('g_finale', ((int)$event->level === 3), 'boolean');
    }

    public function get(string $name): mixed
    {
        if (!array_key_exists($name, $this->params)) {
            throw new \RuntimeException("Parameter '{$name}' not found.");
        }

        return $this->params[$name]['value'];
    }

    public function getType(string $name): string
    {
        if (!array_key_exists($name, $this->params)) {
            throw new \RuntimeException("Parameter '{$name}' not found.");
        }

        return $this->params[$name]['type'];
    }

    public function add(string $name, mixed $value, string $type = 'string'): void
    {
        $this->params[$name] = [
            'value' => $this->cast($value, $type),
            'type' => $type,
        ];
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