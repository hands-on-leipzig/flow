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
}