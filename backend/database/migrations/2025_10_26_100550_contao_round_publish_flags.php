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
        if (!Schema::hasTable('contao_public_rounds')) {
            try {
                // Create table first without foreign key
                Schema::create('contao_public_rounds', function (Blueprint $table) {
                    $table->unsignedInteger('event_id')->primary();
                    $table->boolean('vr1')->default(true);
                    $table->boolean('vr2')->default(false);
                    $table->boolean('vr3')->default(false);
                    $table->boolean('vf')->default(false);
                    $table->boolean('hf')->default(false);
                });
                
                // Try to add foreign key separately (may fail if column types don't match)
                try {
                    Schema::table('contao_public_rounds', function (Blueprint $table) {
                        $table->foreign('event_id')->references('id')->on('event')->onDelete('cascade');
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
        Schema::dropIfExists('contao_public_rounds');
    }
};
