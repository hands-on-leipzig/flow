<?php

use App\Enums\FirstProgram;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quality mass tests are single-program (Challenge or Future 8+).
 * first_program on q_plan is the source of truth downward; q_run mirrors the run selection.
 * Existing rows were Challenge-only; backfill to CHALLENGE.
 */
return new class extends Migration
{
    public function up(): void
    {
        $challengeId = FirstProgram::CHALLENGE->value;

        if (Schema::hasTable('q_plan') && ! Schema::hasColumn('q_plan', 'first_program')) {
            Schema::table('q_plan', function (Blueprint $table) {
                $table->unsignedInteger('first_program')
                    ->default(FirstProgram::CHALLENGE->value)
                    ->after('q_run');

                $table->foreign('first_program')
                    ->references('id')
                    ->on('m_first_program')
                    ->onDelete('restrict');
            });

            DB::table('q_plan')->whereNull('first_program')->update([
                'first_program' => $challengeId,
            ]);
        }

        if (Schema::hasTable('q_run') && ! Schema::hasColumn('q_run', 'first_program')) {
            Schema::table('q_run', function (Blueprint $table) {
                $table->unsignedInteger('first_program')
                    ->nullable()
                    ->after('name');

                $table->foreign('first_program')
                    ->references('id')
                    ->on('m_first_program')
                    ->onDelete('restrict');
            });

            DB::table('q_run')->whereNull('first_program')->update([
                'first_program' => $challengeId,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('q_plan') && Schema::hasColumn('q_plan', 'first_program')) {
            Schema::table('q_plan', function (Blueprint $table) {
                $table->dropForeign(['first_program']);
                $table->dropColumn('first_program');
            });
        }

        if (Schema::hasTable('q_run') && Schema::hasColumn('q_run', 'first_program')) {
            Schema::table('q_run', function (Blueprint $table) {
                $table->dropForeign(['first_program']);
                $table->dropColumn('first_program');
            });
        }
    }
};
