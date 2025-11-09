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
        // Drop foreign key constraints first (may not exist)
        // Wrap each Schema::table call in try-catch because Laravel executes immediately
        try {
            Schema::table('plan', function (Blueprint $table) {
                $table->dropForeign(['level']);
            });
        } catch (\Throwable $e) {
            // Foreign key might not exist; ignore
        }
        
        try {
            Schema::table('plan', function (Blueprint $table) {
                $table->dropForeign(['first_program']);
            });
        } catch (\Throwable $e) {
            // Foreign key might not exist; ignore
        }
        
        // Drop the columns (only if they exist)
        if (Schema::hasColumn('plan', 'level') || Schema::hasColumn('plan', 'first_program')) {
            $columnsToDrop = [];
            if (Schema::hasColumn('plan', 'level')) {
                $columnsToDrop[] = 'level';
            }
            if (Schema::hasColumn('plan', 'first_program')) {
                $columnsToDrop[] = 'first_program';
            }
            if (!empty($columnsToDrop)) {
                try {
                    Schema::table('plan', function (Blueprint $table) use ($columnsToDrop) {
                        $table->dropColumn($columnsToDrop);
                    });
                } catch (\Throwable $e) {
                    // Columns might not exist or can't be dropped; ignore
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plan', function (Blueprint $table) {
            // Re-add the columns
            $table->unsignedBigInteger('level');
            $table->unsignedBigInteger('first_program');
            
            // Re-add foreign key constraints
            $table->foreign('level')->references('id')->on('m_level');
            $table->foreign('first_program')->references('id')->on('m_first_program');
        });
    }
};
