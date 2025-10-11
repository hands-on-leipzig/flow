<?php

namespace App\Support;

use RuntimeException;
use App\Support\PlanParameter;

class PlanParameterCompat
{
    private static ?PlanParameter $instance = null;

    public static function load(int $planId): void
    {
        // Neue Instanz für diesen Plan
        self::$instance = new PlanParameter($planId);
    }

    public static function get(string $key): mixed
    {
        if (!self::$instance) {
            throw new RuntimeException("PlanParameter not loaded. Call PlanParameterCompat::load(\$planId) first.");
        }
        return self::$instance->get($key);
    }

    public static function add(string $key, mixed $value, string $type = 'string'): void
    {
        if (!self::$instance) {
            throw new RuntimeException("PlanParameter not loaded. Call PlanParameterCompat::load(\$planId) first.");
        }
        self::$instance->add($key, $value, $type);
    }
}