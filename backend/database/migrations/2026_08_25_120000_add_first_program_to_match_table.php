<?php

use App\Enums\FirstProgram;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Match rows belong to one Challenge-shaped program so C and F8 can coexist on a plan.
 * Existing rows were Challenge-only; default/backfill to CHALLENGE.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('match') || Schema::hasColumn('match', 'first_program')) {
            return;
        }

        Schema::table('match', function (Blueprint $table) {
            $table->unsignedInteger('first_program')
                ->default(FirstProgram::CHALLENGE->value)
                ->after('plan');

            $table->unique(
                ['plan', 'first_program', 'round', 'match_no'],
                'match_plan_program_round_match_unique'
            );

            $table->foreign('first_program')
                ->references('id')
                ->on('m_first_program')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('match') || ! Schema::hasColumn('match', 'first_program')) {
            return;
        }

        Schema::table('match', function (Blueprint $table) {
            $table->dropForeign(['first_program']);
            $table->dropUnique('match_plan_program_round_match_unique');
            $table->dropColumn('first_program');
        });
    }
};
