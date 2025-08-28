<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class LegacyConstantsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Legacy-Konstanten laden (falls Datei existiert)
        $legacy = base_path('legacy/generator/generator_db.php');
        if (file_exists($legacy)) {
            require_once $legacy;
        }
    }

    public function boot(): void
    {
        // nichts
    }
}