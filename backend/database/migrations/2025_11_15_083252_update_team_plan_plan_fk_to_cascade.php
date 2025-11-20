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
        if (!Schema::hasTable('team_plan') || !Schema::hasColumn('team_plan', 'plan')) {
            return;
        }

        // Find the actual foreign key name for the 'plan' column
        $fkName = $this->getForeignKeyName('team_plan', 'plan');
        
        // Check if FK already has CASCADE delete
        if ($fkName) {
            $hasCascade = $this->hasCascadeDelete('team_plan', 'plan');
            if ($hasCascade) {
                // Already has CASCADE, nothing to do
                return;
            }
            
            // Drop existing FK to recreate with CASCADE
            Schema::table('team_plan', function (Blueprint $table) use ($fkName) {
                try {
                    $table->dropForeign($fkName);
                } catch (\Throwable $e) {
                    // Constraint might already be dropped; ignore
                }
            });
        }

        // Create FK with CASCADE delete - wrap in try-catch to handle case where it already exists
        try {
            Schema::table('team_plan', function (Blueprint $table) {
                $table->foreign('plan')->references('id')->on('plan')->onDelete('cascade');
            });
        } catch (\Throwable $e) {
            // Check if the error is because the constraint already exists
            // If FK already exists with CASCADE, that's fine - we can ignore the error
            $fkName = $this->getForeignKeyName('team_plan', 'plan');
            if ($fkName && $this->hasCascadeDelete('team_plan', 'plan')) {
                // Constraint already exists with CASCADE - that's what we want, so ignore error
                return;
            }
            // Otherwise, re-throw the error as it's a real problem
            throw $e;
        }
    }

    /**
     * Get the foreign key name for a column
     */
    private function getForeignKeyName(string $table, string $column): ?string
    {
        $result = DB::selectOne("
            SELECT CONSTRAINT_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1
        ", [$table, $column]);

        return $result ? $result->CONSTRAINT_NAME : null;
    }

    /**
     * Check if foreign key has CASCADE delete
     */
    private function hasCascadeDelete(string $table, string $column): bool
    {
        $result = DB::selectOne("
            SELECT rc.DELETE_RULE
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
            WHERE kcu.TABLE_SCHEMA = DATABASE()
                AND kcu.TABLE_NAME = ?
                AND kcu.COLUMN_NAME = ?
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1
        ", [$table, $column]);

        return $result && $result->DELETE_RULE === 'CASCADE';
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('team_plan') || !Schema::hasColumn('team_plan', 'plan')) {
            return;
        }

        // Find the actual foreign key name for the 'plan' column
        $fkName = $this->getForeignKeyName('team_plan', 'plan');
        
        if ($fkName) {
            // Drop existing FK
            Schema::table('team_plan', function (Blueprint $table) use ($fkName) {
                try {
                    $table->dropForeign($fkName);
                } catch (\Throwable $e) {
                    // Constraint might already be dropped; ignore
                }
            });
        }

        // Recreate FK without CASCADE delete (default behavior)
        Schema::table('team_plan', function (Blueprint $table) {
            $table->foreign('plan')->references('id')->on('plan');
        });
    }
};
