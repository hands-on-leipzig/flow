<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('contao_public_rounds')) {
            // nothing to do
            return;
        }

        $columns = ['vr1','vr2','vr3','af','vf','hf'];

        foreach ($columns as $col) {
            if (!Schema::hasColumn('contao_public_rounds', $col)) {
                continue;
            }

            // make nullable
            try {
                DB::statement(sprintf(
                    'ALTER TABLE `contao_public_rounds` MODIFY `%s` TINYINT(1) NULL DEFAULT NULL',
                    $col
                ));
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('contao_public_rounds')) {
            return;
        }

        // revert to previous NOT NULL defaults. The original migration set vr1 default true, others false.
        $revertDefaults = [
            'vr1' => 1,
            'vr2' => 0,
            'vr3' => 0,
            'af' => 0,
            'vf' => 0,
            'hf' => 0,
        ];

        foreach ($revertDefaults as $col => $def) {
            if (!Schema::hasColumn('contao_public_rounds', $col)) {
                continue;
            }

            try {
                DB::statement(sprintf(
                    'ALTER TABLE `contao_public_rounds` MODIFY `%s` TINYINT(1) NOT NULL DEFAULT %d',
                    $col,
                    (int) $def
                ));
            } catch (\Throwable $e) {
                // ignore errors
            }
        }
    }
};

