<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use DateTime;

/**
 * PlanParameter
 *
 * Loads and encapsulates all parameters for a plan (including event info, overrides, type conversion).
 * No static state – each instance is independent.
 */
class PlanParameter
{
    private array $params = [];

    public function __construct(private readonly int $planId)
    {
        $this->init();
    }

    /**
     * Factory method – syntactic sugar.
     */
    public static function load(int $planId): self
    {
        return new self($planId);
    }

    /**
     * Returns the value of a parameter.
     */
    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->params)) {
            throw new RuntimeException("Parameter '{$key}' not found.");
        }

        return $this->params[$key]['value'];
    }

    /**
     * Fügt oder überschreibt einen Parameter (z. B. für dynamische Ergänzungen).
     */
    public function add(string $key, mixed $value, string $type = 'string'): void
    {
        $this->params[$key] = [
            'value' => $this->cast($value, $type),
            'type'  => $type,
        ];
    }

    /**
     * Internal initializer – loads event and plan data from DB.
     */
    private function init(): void
    {
        $this->add('g_plan', $this->planId, 'integer');

        $eventId = DB::table('plan')
            ->where('id', $this->planId)
            ->value('event');

        if (!$eventId) {
            throw new RuntimeException("Kein Event zur Plan-ID {$this->planId} gefunden.");
        }

        $event = DB::table('event')
            ->select('level', 'date', 'days')
            ->where('id', $eventId)
            ->first();

        if (!$event) {
            throw new RuntimeException("Event-ID {$eventId} nicht gefunden.");
        }

        $this->add('g_level', $event->level, 'integer');
        $this->add('g_date', $event->date, 'date');
        $this->add('g_days', $event->days, 'integer');
        $this->add('g_finale', ((int)$event->level === 3), 'boolean');

        // Base parameters from m_parameter
        $base = DB::table('m_parameter')
            ->select('id', 'name', 'type', 'value', 'min', 'max', 'step')
            ->get()
            ->keyBy('id');

        // Plan-specific overrides
        $overrides = DB::table('plan_param_value')
            ->select('parameter', 'set_value')
            ->where('plan', $this->planId)
            ->get()
            ->keyBy('parameter');

        foreach ($base as $id => $row) {
            $raw = $overrides->has($id)
                ? $overrides[$id]->set_value
                : $row->value;

            // Validate parameter constraints if override exists
            if ($overrides->has($id)) {
                $this->validateParameter($row, $raw);
            }

            $this->params[$row->name] = [
                'value' => $this->cast($raw, $row->type),
                'type'  => $row->type,
            ];
        }
    }

    /**
     * Validates parameter constraints (min, max, step).
     */
    private function validateParameter(object $param, mixed $value): void
    {
        // Skip validation if constraints are not set
        if ($param->min === null && $param->max === null && $param->step === null) {
            return;
        }

        $numericValue = $this->cast($value, $param->type);
        
        // Validate range
        if ($param->min !== null && $numericValue < $param->min) {
            throw new RuntimeException("Parameter '{$param->name}' value {$numericValue} is below minimum {$param->min}.");
        }
        
        if ($param->max !== null && $numericValue > $param->max) {
            throw new RuntimeException("Parameter '{$param->name}' value {$numericValue} is above maximum {$param->max}.");
        }
        
        // Validate step formula: value must be min + n * step
        if ($param->step !== null && $param->step > 0) {
            $min = $param->min ?? 0;
            $step = $param->step;
            $expectedValue = $min + (int)(($numericValue - $min) / $step) * $step;
            
            if (abs($numericValue - $expectedValue) > 0.0001) { // Allow small floating point errors
                throw new RuntimeException("Parameter '{$param->name}' value {$numericValue} does not follow step formula (min: {$min}, step: {$step}).");
            }
        }
    }

    /**
     * Type conversion according to database schema.
     */
    private function cast(mixed $value, ?string $type): mixed
    {
        if ($value === null) return null;

        return match ($type) {
            'integer' => (int)$value,
            'boolean' => (bool)$value,
            'float'   => (float)$value,
            'date'    => new DateTime($value),
            default   => (string)$value,
        };
    }

    /**
     * Optional: returns all parameters (debugging, tests)
     */
    public function all(): array
    {
        return $this->params;
    }
}