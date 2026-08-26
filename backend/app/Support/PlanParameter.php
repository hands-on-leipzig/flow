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

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->params);
    }

    /**
     * Returns the value of a parameter.
     * Pass a second argument when the key is absent (off-event programs are not loaded).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, $this->params)) {
            if (func_num_args() >= 2) {
                return $default;
            }

            throw new RuntimeException("Parameter '{$key}' nicht gefunden.");
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

        ProgramPresence::purgeParametersOutsideEvent($this->planId);
        ProgramPresence::syncChallengeShapedModes($this->planId);

        $attachedProgramIds = ProgramPresence::attachedProgramIds((int) $eventId);

        // Base parameters: joint / unscoped, plus programs attached to this event
        $base = DB::table('m_parameter')
            ->select('id', 'name', 'type', 'value', 'min', 'max', 'step', 'first_program')
            ->get()
            ->keyBy('id');

        // Plan-specific overrides
        $overrides = DB::table('plan_param_value')
            ->select('parameter', 'set_value')
            ->where('plan', $this->planId)
            ->get()
            ->keyBy('parameter');

        foreach ($base as $id => $row) {
            $programId = (int) ($row->first_program ?? 0);
            if ($programId > 0 && ! in_array($programId, $attachedProgramIds, true)) {
                continue;
            }

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

        // Off programs store 0 for team/hardware counts (below catalog min). Support-plan checks handle them.
        if (str_ends_with($param->name, '_teams')
            || str_ends_with($param->name, '_tables')
            || str_ends_with($param->name, '_fields')) {
            return;
        }

        // Special handling for time parameters
        if ($param->type === 'time') {
            $this->validateTimeParameter($param, $value);
            return;
        }

        $numericValue = $this->cast($value, $param->type);
        
        // Validate range
        if ($param->min !== null && $numericValue < $param->min) {
            throw new RuntimeException("Parameter '{$param->name}': Wert {$numericValue} ist unter dem Minimum {$param->min}. Bitte korrigiere den Wert.");
        }
        
        if ($param->max !== null && $numericValue > $param->max) {
            throw new RuntimeException("Parameter '{$param->name}': Wert {$numericValue} ist über dem Maximum {$param->max}. Bitte korrigiere den Wert.");
        }
        
        // Validate step formula: value must be min + n * step
        if ($param->step !== null && $param->step > 0) {
            $min = $param->min ?? 0;
            $step = $param->step;
            $expectedValue = $min + (int)(($numericValue - $min) / $step) * $step;
            
            if (abs($numericValue - $expectedValue) > 0.0001) { // Allow small floating point errors
                throw new RuntimeException("Parameter '{$param->name}': Wert {$numericValue} entspricht nicht der Schrittformel (Min: {$min}, Schritt: {$step}). Bitte korrigiere den Wert.");
            }
        }
    }

    /**
     * Validates time parameters with step constraints.
     */
    private function validateTimeParameter(object $param, mixed $value): void
    {
        // Convert time strings to minutes for validation
        $valueMinutes = $this->timeToMinutes($value);
        $minMinutes = $this->timeToMinutes($param->min);
        $maxMinutes = $this->timeToMinutes($param->max);
        
        // Validate range
        if ($param->min !== null && $valueMinutes < $minMinutes) {
            throw new RuntimeException("Parameter '{$param->name}': Zeit {$value} ist vor dem Minimum {$param->min}. Bitte korrigiere die Zeit.");
        }
        
        if ($param->max !== null && $valueMinutes > $maxMinutes) {
            throw new RuntimeException("Parameter '{$param->name}': Zeit {$value} ist nach dem Maximum {$param->max}. Bitte korrigiere die Zeit.");
        }
        
        // Validate step formula for time: minutes must be multiples of step
        if ($param->step !== null && $param->step > 0) {
            if ($valueMinutes % $param->step !== 0) {
                throw new RuntimeException("Parameter '{$param->name}': Zeit {$value} entspricht nicht der Schrittformel (Schritt: {$param->step} Minuten). Bitte korrigiere die Zeit.");
            }
        }
    }

    /**
     * Converts time string (HH:MM) to minutes since midnight.
     */
    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);
        return (int)$hours * 60 + (int)$minutes;
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