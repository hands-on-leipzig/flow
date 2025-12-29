<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Removes the incorrect foreign key constraint on q_plan_team.team.
     * The team column should contain sequential numbers (1, 2, 3...), not foreign keys to team.id.
     */
    public function up(): void
    {
        if (Schema::hasTable('q_plan_team') && Schema::hasColumn('q_plan_team', 'team')) {
            try {
                // Check if foreign key exists
                $existingFk = DB::select("
                    SELECT CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = ?
                    AND TABLE_NAME = 'q_plan_team'
                    AND COLUMN_NAME = 'team'
                    AND REFERENCED_TABLE_NAME = 'team'
                    LIMIT 1
                ", [DB::connection()->getDatabaseName()]);
                
                if (!empty($existingFk)) {
                    $constraintName = $existingFk[0]->CONSTRAINT_NAME;
                    DB::statement("ALTER TABLE `q_plan_team` DROP FOREIGN KEY `{$constraintName}`");
                    echo "  ✓ Removed FK: q_plan_team.team → team.id\n";
                } else {
                    echo "  ℹ️  Foreign key q_plan_team.team → team.id does not exist (already removed or never existed)\n";
                }
            } catch (\Throwable $e) {
                echo "  ⚠️  Failed to remove FK q_plan_team.team: " . $e->getMessage() . "\n";
                throw $e;
            }
        }
    }

    /**
     * Reverse the migrations.
     * 
     * Re-adds the foreign key constraint (not recommended as it conflicts with code logic).
     */
    public function down(): void
    {
        if (Schema::hasTable('q_plan_team') && Schema::hasColumn('q_plan_team', 'team') && Schema::hasTable('team')) {
            try {
                // Check if foreign key already exists
                $existingFk = DB::select("
                    SELECT CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = ?
                    AND TABLE_NAME = 'q_plan_team'
                    AND COLUMN_NAME = 'team'
                    AND REFERENCED_TABLE_NAME = 'team'
                    LIMIT 1
                ", [DB::connection()->getDatabaseName()]);
                
                if (empty($existingFk)) {
                    $fkName = "q_plan_team_team_foreign";
                    DB::statement("ALTER TABLE `q_plan_team` ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`team`) REFERENCES `team` (`id`) ON DELETE CASCADE");
                    echo "  ✓ Re-added FK: q_plan_team.team → team.id (ON DELETE CASCADE)\n";
                }
            } catch (\Throwable $e) {
                echo "  ⚠️  Failed to re-add FK q_plan_team.team: " . $e->getMessage() . "\n";
                // Don't throw - migration down should be tolerant
            }
        }
    }
};
