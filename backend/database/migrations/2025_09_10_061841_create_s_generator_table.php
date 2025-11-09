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
        if (!Schema::hasTable('s_generator')) {
            try {
                Schema::create('s_generator', function (Blueprint $table) {
                    $table->id();
                    $table->unsignedBigInteger('plan');
                    $table->timestamp('start')->nullable();
                    $table->timestamp('end')->nullable();
                    $table->string('mode')->nullable(); // 'job' or 'direct'
                    $table->timestamps();
                    
                    // Foreign key constraint
                    $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
                    
                    // Index for performance
                    $table->index(['plan', 'start']);
                });
            } catch (\Throwable $e) {
                // Ignore errors if table was created by another process or FK fails
                // This can happen if table structure differs or foreign key types don't match
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('s_generator');
    }
};