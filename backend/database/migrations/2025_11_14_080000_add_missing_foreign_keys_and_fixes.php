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
     * Adds missing foreign keys and fixes existing ones based on requirements.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        
        // Disable foreign key checks
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF;');
        }
        
        try {
            // Fix q_plan_team.team type from int(11) to int(10) unsigned
            if (Schema::hasTable('q_plan_team') && Schema::hasColumn('q_plan_team', 'team')) {
                try {
                    // Check current type
                    $columnInfo = DB::select("
                        SELECT COLUMN_TYPE
                        FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = ?
                        AND TABLE_NAME = 'q_plan_team'
                        AND COLUMN_NAME = 'team'
                    ", [DB::connection()->getDatabaseName()]);
                    
                    if (!empty($columnInfo) && stripos($columnInfo[0]->COLUMN_TYPE, 'int(11)') !== false) {
                        // Drop foreign key if exists
                        $fkInfo = DB::select("
                            SELECT CONSTRAINT_NAME
                            FROM information_schema.KEY_COLUMN_USAGE
                            WHERE TABLE_SCHEMA = ?
                            AND TABLE_NAME = 'q_plan_team'
                            AND COLUMN_NAME = 'team'
                            AND REFERENCED_TABLE_NAME IS NOT NULL
                            LIMIT 1
                        ", [DB::connection()->getDatabaseName()]);
                        
                        if (!empty($fkInfo)) {
                            $constraintName = $fkInfo[0]->CONSTRAINT_NAME;
                            try {
                                DB::statement("ALTER TABLE `q_plan_team` DROP FOREIGN KEY `{$constraintName}`");
                            } catch (\Throwable $e) {
                                // Ignore if FK doesn't exist
                            }
                        }
                        
                        DB::statement("ALTER TABLE `q_plan_team` MODIFY COLUMN `team` INT(10) UNSIGNED NOT NULL");
                        echo "  ✓ Fixed q_plan_team.team type to INT(10) UNSIGNED\n";
                    }
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to fix q_plan_team.team type: " . $e->getMessage() . "\n";
                }
            }
            
            // Make q_plan.q_run nullable
            if (Schema::hasTable('q_plan') && Schema::hasColumn('q_plan', 'q_run')) {
                try {
                    $columnInfo = DB::select("
                        SELECT IS_NULLABLE
                        FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = ?
                        AND TABLE_NAME = 'q_plan'
                        AND COLUMN_NAME = 'q_run'
                    ", [DB::connection()->getDatabaseName()]);
                    
                    if (!empty($columnInfo) && $columnInfo[0]->IS_NULLABLE === 'NO') {
                        DB::statement("ALTER TABLE `q_plan` MODIFY COLUMN `q_run` INT(10) UNSIGNED NULL");
                        echo "  ✓ Made q_plan.q_run nullable\n";
                    }
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to make q_plan.q_run nullable: " . $e->getMessage() . "\n";
                }
            }
            
            // Make s_generator.plan nullable
            if (Schema::hasTable('s_generator') && Schema::hasColumn('s_generator', 'plan')) {
                try {
                    $columnInfo = DB::select("
                        SELECT IS_NULLABLE
                        FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = ?
                        AND TABLE_NAME = 's_generator'
                        AND COLUMN_NAME = 'plan'
                    ", [DB::connection()->getDatabaseName()]);
                    
                    if (!empty($columnInfo) && $columnInfo[0]->IS_NULLABLE === 'NO') {
                        // Drop foreign key first if exists
                        $fkInfo = DB::select("
                            SELECT CONSTRAINT_NAME
                            FROM information_schema.KEY_COLUMN_USAGE
                            WHERE TABLE_SCHEMA = ?
                            AND TABLE_NAME = 's_generator'
                            AND COLUMN_NAME = 'plan'
                            AND REFERENCED_TABLE_NAME IS NOT NULL
                            LIMIT 1
                        ", [DB::connection()->getDatabaseName()]);
                        
                        if (!empty($fkInfo)) {
                            $constraintName = $fkInfo[0]->CONSTRAINT_NAME;
                            try {
                                DB::statement("ALTER TABLE `s_generator` DROP FOREIGN KEY `{$constraintName}`");
                            } catch (\Throwable $e) {
                                // Ignore if FK doesn't exist
                            }
                        }
                        
                        DB::statement("ALTER TABLE `s_generator` MODIFY COLUMN `plan` INT(10) UNSIGNED NULL");
                        echo "  ✓ Made s_generator.plan nullable\n";
                    }
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to make s_generator.plan nullable: " . $e->getMessage() . "\n";
                }
            }
            
            // Add unique constraint to m_parameter.name
            if (Schema::hasTable('m_parameter') && Schema::hasColumn('m_parameter', 'name')) {
                try {
                    // Check if unique index already exists
                    $indexes = DB::select("
                        SELECT INDEX_NAME
                        FROM information_schema.STATISTICS
                        WHERE TABLE_SCHEMA = ?
                        AND TABLE_NAME = 'm_parameter'
                        AND COLUMN_NAME = 'name'
                        AND NON_UNIQUE = 0
                    ", [DB::connection()->getDatabaseName()]);
                    
                    if (empty($indexes)) {
                        DB::statement("ALTER TABLE `m_parameter` ADD UNIQUE KEY `m_parameter_name_unique` (`name`)");
                        echo "  ✓ Added unique constraint to m_parameter.name\n";
                    }
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to add unique constraint to m_parameter.name: " . $e->getMessage() . "\n";
                }
            }
            
            // Add foreign keys
            $foreignKeys = [
                // 1. activity.activity_group → activity_group.id (CASCADE)
                ['table' => 'activity', 'column' => 'activity_group', 'ref_table' => 'activity_group', 'ref_column' => 'id', 'on_delete' => 'CASCADE'],
                
                // 2. activity.extra_block → extra_block.id (SET NULL)
                ['table' => 'activity', 'column' => 'extra_block', 'ref_table' => 'extra_block', 'ref_column' => 'id', 'on_delete' => 'SET NULL'],
                
                // 3. activity_group.plan → plan.id (CASCADE)
                ['table' => 'activity_group', 'column' => 'plan', 'ref_table' => 'plan', 'ref_column' => 'id', 'on_delete' => 'CASCADE'],
                
                // 4. event.regional_partner → regional_partner.id (RESTRICT)
                ['table' => 'event', 'column' => 'regional_partner', 'ref_table' => 'regional_partner', 'ref_column' => 'id', 'on_delete' => 'RESTRICT'],
                
                // 5. extra_block.plan → plan.id (CASCADE)
                ['table' => 'extra_block', 'column' => 'plan', 'ref_table' => 'plan', 'ref_column' => 'id', 'on_delete' => 'CASCADE'],
                
                // 6. match.plan → plan.id (CASCADE)
                ['table' => 'match', 'column' => 'plan', 'ref_table' => 'plan', 'ref_column' => 'id', 'on_delete' => 'CASCADE'],
                
                // 7. news_user.user_id → user.id (SET NULL)
                ['table' => 'news_user', 'column' => 'user_id', 'ref_table' => 'user', 'ref_column' => 'id', 'on_delete' => 'SET NULL'],
                
                // 8. plan.event → event.id (RESTRICT)
                ['table' => 'plan', 'column' => 'event', 'ref_table' => 'event', 'ref_column' => 'id', 'on_delete' => 'RESTRICT'],
                
                // 9. plan_param_value.plan → plan.id (CASCADE)
                ['table' => 'plan_param_value', 'column' => 'plan', 'ref_table' => 'plan', 'ref_column' => 'id', 'on_delete' => 'CASCADE'],
                
                // 10. publication.event → event.id (CASCADE)
                ['table' => 'publication', 'column' => 'event', 'ref_table' => 'event', 'ref_column' => 'id', 'on_delete' => 'CASCADE'],
                
                // 11. q_plan.plan → plan.id (CASCADE)
                ['table' => 'q_plan', 'column' => 'plan', 'ref_table' => 'plan', 'ref_column' => 'id', 'on_delete' => 'CASCADE'],
                
                // 12. q_plan.q_run → q_run.id (CASCADE, nullable)
                ['table' => 'q_plan', 'column' => 'q_run', 'ref_table' => 'q_run', 'ref_column' => 'id', 'on_delete' => 'CASCADE'],
                
                // 13. q_plan_team.q_plan → q_plan.id (CASCADE)
                ['table' => 'q_plan_team', 'column' => 'q_plan', 'ref_table' => 'q_plan', 'ref_column' => 'id', 'on_delete' => 'CASCADE'],
                
                // 14. q_plan_team.team → team.id (CASCADE)
                ['table' => 'q_plan_team', 'column' => 'team', 'ref_table' => 'team', 'ref_column' => 'id', 'on_delete' => 'CASCADE'],
                
                // 15. room_type_room.room → room.id (CASCADE)
                ['table' => 'room_type_room', 'column' => 'room', 'ref_table' => 'room', 'ref_column' => 'id', 'on_delete' => 'CASCADE'],
                
                // 16. room_type_room.event → event.id (CASCADE)
                ['table' => 'room_type_room', 'column' => 'event', 'ref_table' => 'event', 'ref_column' => 'id', 'on_delete' => 'CASCADE'],
                
                // 17. s_generator.plan → plan.id (SET NULL, nullable)
                ['table' => 's_generator', 'column' => 'plan', 'ref_table' => 'plan', 'ref_column' => 'id', 'on_delete' => 'SET NULL'],
                
                // 18. slide.slideshow_id → slideshow.id (CASCADE)
                ['table' => 'slide', 'column' => 'slideshow_id', 'ref_table' => 'slideshow', 'ref_column' => 'id', 'on_delete' => 'CASCADE'],
                
                // 19. slideshow.event → event.id (CASCADE)
                ['table' => 'slideshow', 'column' => 'event', 'ref_table' => 'event', 'ref_column' => 'id', 'on_delete' => 'CASCADE'],
                
                // 20. team.event → event.id (CASCADE)
                ['table' => 'team', 'column' => 'event', 'ref_table' => 'event', 'ref_column' => 'id', 'on_delete' => 'CASCADE'],
            ];
            
            foreach ($foreignKeys as $fk) {
                if (Schema::hasTable($fk['table']) && Schema::hasColumn($fk['table'], $fk['column']) && Schema::hasTable($fk['ref_table'])) {
                    try {
                        // Check if foreign key already exists
                        $existingFk = DB::select("
                            SELECT CONSTRAINT_NAME
                            FROM information_schema.KEY_COLUMN_USAGE
                            WHERE TABLE_SCHEMA = ?
                            AND TABLE_NAME = ?
                            AND COLUMN_NAME = ?
                            AND REFERENCED_TABLE_NAME = ?
                            LIMIT 1
                        ", [DB::connection()->getDatabaseName(), $fk['table'], $fk['column'], $fk['ref_table']]);
                        
                        if (!empty($existingFk)) {
                            // Drop existing FK to update ON DELETE action
                            $constraintName = $existingFk[0]->CONSTRAINT_NAME;
                            try {
                                DB::statement("ALTER TABLE `{$fk['table']}` DROP FOREIGN KEY `{$constraintName}`");
                            } catch (\Throwable $e) {
                                // Ignore if FK doesn't exist
                            }
                        }
                        
                        // Add foreign key
                        $fkName = "{$fk['table']}_{$fk['column']}_foreign";
                        DB::statement("ALTER TABLE `{$fk['table']}` ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`{$fk['column']}`) REFERENCES `{$fk['ref_table']}` (`{$fk['ref_column']}`) ON DELETE {$fk['on_delete']}");
                        echo "  ✓ Added FK: {$fk['table']}.{$fk['column']} → {$fk['ref_table']}.{$fk['ref_column']} (ON DELETE {$fk['on_delete']})\n";
                    } catch (\Throwable $e) {
                        echo "  ⚠️  Failed to add FK {$fk['table']}.{$fk['column']}: " . $e->getMessage() . "\n";
                    }
                }
            }
            
            // Update existing foreign keys with new ON DELETE actions
            $fkUpdates = [
                // room.event → event.id (CASCADE)
                ['table' => 'room', 'column' => 'event', 'ref_table' => 'event', 'on_delete' => 'CASCADE'],
                
                // table_event.event → event.id (CASCADE)
                ['table' => 'table_event', 'column' => 'event', 'ref_table' => 'event', 'on_delete' => 'CASCADE'],
                
                // team_plan.team → team.id (CASCADE)
                ['table' => 'team_plan', 'column' => 'team', 'ref_table' => 'team', 'on_delete' => 'CASCADE'],
                
                // user_regional_partner.user → user.id (CASCADE)
                ['table' => 'user_regional_partner', 'column' => 'user', 'ref_table' => 'user', 'on_delete' => 'CASCADE'],
                
                // user_regional_partner.regional_partner → regional_partner.id (CASCADE)
                ['table' => 'user_regional_partner', 'column' => 'regional_partner', 'ref_table' => 'regional_partner', 'on_delete' => 'CASCADE'],
            ];
            
            foreach ($fkUpdates as $fk) {
                if (Schema::hasTable($fk['table']) && Schema::hasColumn($fk['table'], $fk['column'])) {
                    try {
                        // Get existing FK
                        $existingFk = DB::select("
                            SELECT CONSTRAINT_NAME
                            FROM information_schema.KEY_COLUMN_USAGE
                            WHERE TABLE_SCHEMA = ?
                            AND TABLE_NAME = ?
                            AND COLUMN_NAME = ?
                            AND REFERENCED_TABLE_NAME = ?
                            LIMIT 1
                        ", [DB::connection()->getDatabaseName(), $fk['table'], $fk['column'], $fk['ref_table']]);
                        
                        if (!empty($existingFk)) {
                            $constraintName = $existingFk[0]->CONSTRAINT_NAME;
                            
                            // Get referenced column
                            $refCol = DB::select("
                                SELECT REFERENCED_COLUMN_NAME
                                FROM information_schema.KEY_COLUMN_USAGE
                                WHERE TABLE_SCHEMA = ?
                                AND TABLE_NAME = ?
                                AND COLUMN_NAME = ?
                                AND CONSTRAINT_NAME = ?
                                LIMIT 1
                            ", [DB::connection()->getDatabaseName(), $fk['table'], $fk['column'], $constraintName]);
                            
                            $refColumn = !empty($refCol) ? $refCol[0]->REFERENCED_COLUMN_NAME : 'id';
                            
                            // Drop existing FK
                            DB::statement("ALTER TABLE `{$fk['table']}` DROP FOREIGN KEY `{$constraintName}`");
                            
                            // Re-add with new ON DELETE action
                            $fkName = "{$fk['table']}_{$fk['column']}_foreign";
                            DB::statement("ALTER TABLE `{$fk['table']}` ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`{$fk['column']}`) REFERENCES `{$fk['ref_table']}` (`{$refColumn}`) ON DELETE {$fk['on_delete']}");
                            echo "  ✓ Updated FK: {$fk['table']}.{$fk['column']} → {$fk['ref_table']}.{$refColumn} (ON DELETE {$fk['on_delete']})\n";
                        }
                    } catch (\Throwable $e) {
                        echo "  ⚠️  Failed to update FK {$fk['table']}.{$fk['column']}: " . $e->getMessage() . "\n";
                    }
                }
            }
            
        } finally {
            // Re-enable foreign key checks
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON;');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: We don't reverse this migration as it's adding referential integrity.
        // Reverting would remove important foreign key constraints.
    }
};

