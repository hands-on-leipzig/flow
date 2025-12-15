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
        if (!Schema::hasTable('s_generator')) {
            return;
        }

        // Add column first (if it doesn't exist)
        if (!Schema::hasColumn('s_generator', 'user')) {
            Schema::table('s_generator', function (Blueprint $table) {
                $table->unsignedInteger('user')->nullable()->after('plan');
            });
        }

        // Add index separately (if it doesn't exist)
        try {
            $indexes = DB::select("
                SELECT INDEX_NAME 
                FROM information_schema.STATISTICS 
                WHERE TABLE_SCHEMA = ? 
                AND TABLE_NAME = 's_generator' 
                AND COLUMN_NAME = 'user'
            ", [DB::connection()->getDatabaseName()]);
            
            if (empty($indexes)) {
                Schema::table('s_generator', function (Blueprint $table) {
                    $table->index('user');
                });
            }
        } catch (\Throwable $e) {
            // Ignore if index can't be added
        }

        // Add foreign key separately (may fail if column types don't match or data violates constraint)
        // Only add foreign key if user table exists and foreign key doesn't already exist
        if (Schema::hasTable('user') && Schema::hasColumn('user', 'id')) {
            try {
                $foreignKeys = DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM information_schema.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = ? 
                    AND TABLE_NAME = 's_generator' 
                    AND REFERENCED_TABLE_NAME = 'user'
                    AND COLUMN_NAME = 'user'
                ", [DB::connection()->getDatabaseName()]);
                
                if (empty($foreignKeys)) {
                    Schema::table('s_generator', function (Blueprint $table) {
                        $table->foreign('user')->references('id')->on('user')->onDelete('set null');
                    });
                }
            } catch (\Throwable $e) {
                // If foreign key fails, we'll still have the column and index
                // This can happen if there's existing data that violates the constraint
                // or if the user table structure doesn't match expectations
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('s_generator')) {
            return;
        }

        if (!Schema::hasColumn('s_generator', 'user')) {
            return;
        }

        // Drop foreign key first if it exists
        try {
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = ? 
                AND TABLE_NAME = 's_generator' 
                AND REFERENCED_TABLE_NAME = 'user'
                AND COLUMN_NAME = 'user'
            ", [DB::connection()->getDatabaseName()]);
            
            foreach ($foreignKeys as $fk) {
                DB::statement("ALTER TABLE `s_generator` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
            }
        } catch (\Throwable $e) {
            // Ignore if foreign key doesn't exist
        }

        // Drop index
        try {
            Schema::table('s_generator', function (Blueprint $table) {
                $table->dropIndex(['user']);
            });
        } catch (\Throwable $e) {
            // Ignore if index doesn't exist
        }

        // Drop column
        Schema::table('s_generator', function (Blueprint $table) {
            $table->dropColumn('user');
        });
    }
};
