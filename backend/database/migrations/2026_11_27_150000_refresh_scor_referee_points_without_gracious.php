<?php

use App\Scor\ScorSheetService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Freundliches Miteinander left the game points and moved to Grundwerte.
 * Stored referee points still carry it until recomputed.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['scor_sheet', 'scor_form', 'scor_section', 'scor_item', 'event'] as $table) {
            if (!Schema::hasTable($table)) {
                return;
            }
        }

        app(ScorSheetService::class)->refreshRefereePoints();
    }

    public function down(): void {}
};
