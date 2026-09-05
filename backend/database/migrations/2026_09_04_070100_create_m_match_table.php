<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog match plans (Dev-edited, JSON-transported).
 * Shape mirrors live `match`, with teams+tables instead of plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('m_match')) {
            return;
        }

        Schema::create('m_match', function (Blueprint $table) {
            $table->unsignedInteger('id')->autoIncrement();
            $table->unsignedInteger('first_program');
            $table->unsignedInteger('teams');
            $table->unsignedInteger('lanes')->default(1);
            $table->unsignedInteger('tables');
            $table->unsignedInteger('round');
            $table->unsignedInteger('match_no');
            $table->unsignedInteger('table_1');
            $table->unsignedInteger('table_2');
            $table->unsignedInteger('table_1_team');
            $table->unsignedInteger('table_2_team');

            $table->unique(
                ['first_program', 'teams', 'lanes', 'tables', 'round', 'match_no'],
                'm_match_program_teams_lanes_tables_round_match_unique'
            );

            $table->foreign('first_program', 'm_match_first_program_foreign')
                ->references('id')
                ->on('m_first_program')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_match');
    }
};
