<?php

use App\Support\PlanParameter;

/**
 * Shortcut to get a plan parameter.
 *
 * Example:
 *   $teams = pp('e1_teams');
 */
function pp(string $key): mixed
{
    return PlanParameter::get($key);
}