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
        // Only create if table doesn't exist - preserve existing data
        if (!Schema::hasTable('match')) {
            try {
                // Create table first without foreign key
                Schema::create('match', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('plan');
                    $table->integer('round');
                    $table->integer('match_no');
                    $table->integer('table_1');
                    $table->integer('table_2');
                    $table->integer('table_1_team');
                    $table->integer('table_2_team');
                });
                
                // Try to add foreign key separately (may fail if column types don't match)
                try {
                    Schema::table('match', function (Blueprint $table) {
                        $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
                    });
                } catch (\Throwable $e) {
                    // Ignore if foreign key can't be added (type mismatch)
                }
            } catch (\Throwable $e) {
                // Ignore errors if table was created by another process or structure differs
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match');
    }
};
