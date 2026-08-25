<?php

namespace App\Support;

use App\Support\PlanParameter;

/**
 * Trait für planbezogene Klassen.
 * Macht $this->pp(...) verfügbar, wenn $this->params gesetzt ist.
 */
trait UsesPlanParameter
{
    protected PlanParameter $params;

    protected function pp(string $key, mixed $default = null): mixed
    {
        return func_num_args() >= 2
            ? $this->params->get($key, $default)
            : $this->params->get($key);
    }

    /** Explore ceremony recipe; 0 when Explore is not on the event (e_mode not loaded). */
    protected function exploreMode(): int
    {
        return (int) $this->pp('e_mode', 0);
    }

    /** Parameter value when loaded for this event; otherwise default (program not attached). */
    protected function ppLoaded(string $key, mixed $default = 0): mixed
    {
        return $this->params->has($key) ? $this->params->get($key) : $default;
    }
}