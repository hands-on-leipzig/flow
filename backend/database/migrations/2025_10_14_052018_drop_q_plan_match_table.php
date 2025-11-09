<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the q_plan_match table as it's no longer used
        // The application now uses the match table directly
        Schema::dropIfExists('q_plan_match');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate the q_plan_match table if needed for rollback
        if (!Schema::hasTable('q_plan_match')) {
            try {
                Schema::create('q_plan_match', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('q_plan');
                    $table->integer('round');
                    $table->integer('match_no');
                    $table->integer('table_1');
                    $table->integer('table_2');
                    $table->integer('table_1_team');
                    $table->integer('table_2_team');
                });
                
                // Try to add foreign key separately (may fail if column types don't match)
                try {
                    Schema::table('q_plan_match', function (Blueprint $table) {
                        $table->foreign('q_plan')->references('id')->on('q_plan')->onDelete('cascade');
                    });
                } catch (\Throwable $e) {
                    // Ignore if foreign key can't be added (type mismatch)
                }
            } catch (\Throwable $e) {
                // Ignore errors if table creation fails
            }
        }
    }
};
