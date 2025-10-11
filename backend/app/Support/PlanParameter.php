<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use DateTime;

/**
 * PlanParameter
 *
 * Lädt und kapselt alle Parameter für einen Plan (inkl. Event-Infos, Overrides, Typkonvertierung).
 * Keine statischen Zustände – jede Instanz ist unabhängig.
 */
class PlanParameter
{
    private array $params = [];

    public function __construct(private readonly int $planId)
    {
        $this->init();
    }

    /**
     * Factory-Methode – syntaktischer Zucker.
     */
    public static function load(int $planId): self
    {
        return new self($planId);
    }

    /**
     * Gibt den Wert eines Parameters zurück.
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
     * Interner Initialisierer – lädt Event- und Plan-Daten aus der DB.
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

        // Basisparameter aus m_parameter
        $base = DB::table('m_parameter')
            ->select('id', 'name', 'type', 'value')
            ->get()
            ->keyBy('id');

        // Plan-spezifische Overrides
        $overrides = DB::table('plan_param_value')
            ->select('parameter', 'set_value')
            ->where('plan', $this->planId)
            ->get()
            ->keyBy('parameter');

        foreach ($base as $id => $row) {
            $raw = $overrides->has($id)
                ? $overrides[$id]->set_value
                : $row->value;

            $this->params[$row->name] = [
                'value' => $this->cast($raw, $row->type),
                'type'  => $row->type,
            ];
        }
    }

    /**
     * Typkonvertierung entsprechend Datenbankschema.
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
     * Optional: gibt alle Parameter (Debugging, Tests)
     */
    public function all(): array
    {
        return $this->params;
    }
}