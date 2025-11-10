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
        $driver = DB::connection()->getDriverName();
        
        // Disable foreign key checks
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF;');
        }
        
        try {
            // List of all tables that should have int(10) IDs
            $tablesToUpdate = [
                'regional_partner',
                'event',
                'slideshow',
                'slide',
                'publication',
                'user',
                'user_regional_partner',
                'room',
                'room_type_room',
                'team',
                'plan',
                'team_plan',
                'plan_param_value',
                'extra_block',
                'plan_extra_block',
                'activity_group',
                'activity',
                'logo',
                'event_logo',
                'table_event',
                'q_plan',
                'q_plan_match',
                'q_plan_team',
                'q_run',
                'match',
                's_generator',
                'news_user',
                'jobs',
                'failed_jobs',
            ];
            
            // Update ID columns for all tables
            foreach ($tablesToUpdate as $table) {
                if (Schema::hasTable($table)) {
                    try {
                        // Check current ID column type
                        $columnInfo = DB::select("
                            SELECT COLUMN_TYPE, COLUMN_KEY, EXTRA
                            FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = ?
                            AND TABLE_NAME = ?
                            AND COLUMN_NAME = 'id'
                        ", [DB::connection()->getDatabaseName(), $table]);
                        
                        if (!empty($columnInfo)) {
                            $columnType = $columnInfo[0]->COLUMN_TYPE;
                            $isAutoIncrement = strpos($columnInfo[0]->EXTRA, 'auto_increment') !== false;
                            
                            // Only update if it's bigint
                            if (strpos(strtolower($columnType), 'bigint') !== false) {
                                if ($isAutoIncrement) {
                                    // For AUTO_INCREMENT columns, we need to temporarily remove it
                                    DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `id` INT(10) UNSIGNED NOT NULL");
                                    DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT");
                                } else {
                                    DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `id` INT(10) UNSIGNED NOT NULL");
                                }
                                echo "  ✓ Updated {$table}.id to INT(10)\n";
                            }
                        }
                    } catch (\Throwable $e) {
                        echo "  ⚠️  Failed to update {$table}.id: " . $e->getMessage() . "\n";
                    }
                }
            }
            
            // Update foreign key columns that reference int(10) tables
            $foreignKeyUpdates = [
                'event' => ['regional_partner', 'level', 'season'],
                'slideshow' => ['event'],
                'slide' => ['slideshow'],
                'publication' => ['event'],
                'user' => ['selection_regional_partner', 'selection_event'],
                'user_regional_partner' => ['user', 'regional_partner'],
                'room' => ['room_type', 'event'],
                'room_type_room' => ['room_type', 'room', 'event'],
                'team' => ['event', 'room', 'first_program'],
                'plan' => ['event', 'level', 'first_program'],
                'team_plan' => ['team', 'plan', 'room'],
                'plan_param_value' => ['plan', 'parameter'],
                'extra_block' => ['plan', 'room', 'first_program'],
                'plan_extra_block' => ['plan', 'extra_block'],
                'activity_group' => ['activity_type_detail', 'plan'],
                'activity' => ['activity_group', 'room_type', 'activity_type_detail', 'plan_extra_block', 'extra_block'],
                'logo' => ['event', 'regional_partner'],
                'event_logo' => ['event', 'logo'],
                'table_event' => ['event'],
                'q_plan' => ['event', 'level', 'plan', 'q_run'],
                'q_plan_match' => ['q_plan'],
                'q_plan_team' => ['q_plan'],
                'q_run' => ['q_plan', 'team'],
                'match' => ['plan'],
                's_generator' => ['plan'],
                'news_user' => ['user_id', 'news_id'],
            ];
            
            foreach ($foreignKeyUpdates as $table => $columns) {
                if (Schema::hasTable($table)) {
                    foreach ($columns as $column) {
                        if (Schema::hasColumn($table, $column)) {
                            try {
                                // Check current column type
                                $columnInfo = DB::select("
                                    SELECT COLUMN_TYPE, IS_NULLABLE
                                    FROM information_schema.COLUMNS
                                    WHERE TABLE_SCHEMA = ?
                                    AND TABLE_NAME = ?
                                    AND COLUMN_NAME = ?
                                ", [DB::connection()->getDatabaseName(), $table, $column]);
                                
                                if (!empty($columnInfo)) {
                                    $columnType = $columnInfo[0]->COLUMN_TYPE;
                                    $isNullable = $columnInfo[0]->IS_NULLABLE === 'YES';
                                    
                                    // Only update if it's bigint
                                    if (strpos(strtolower($columnType), 'bigint') !== false) {
                                        // Get referenced table info BEFORE dropping FK
                                        $fkInfo = DB::select("
                                            SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                                            FROM information_schema.KEY_COLUMN_USAGE
                                            WHERE TABLE_SCHEMA = ?
                                            AND TABLE_NAME = ?
                                            AND COLUMN_NAME = ?
                                            AND REFERENCED_TABLE_NAME IS NOT NULL
                                            LIMIT 1
                                        ", [DB::connection()->getDatabaseName(), $table, $column]);
                                        
                                        $referencedTable = !empty($fkInfo) ? $fkInfo[0]->REFERENCED_TABLE_NAME : null;
                                        $referencedColumn = !empty($fkInfo) ? ($fkInfo[0]->REFERENCED_COLUMN_NAME ?? 'id') : 'id';
                                        $constraintName = !empty($fkInfo) ? $fkInfo[0]->CONSTRAINT_NAME : null;
                                        
                                        // Drop foreign key if it exists
                                        if ($constraintName) {
                                            try {
                                                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraintName}`");
                                            } catch (\Throwable $e) {
                                                // Ignore if FK doesn't exist
                                            }
                                        }
                                        
                                        // Update column type
                                        $nullClause = $isNullable ? 'NULL' : 'NOT NULL';
                                        DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` INT(10) UNSIGNED {$nullClause}");
                                        
                                        // Re-add foreign key if we know the referenced table
                                        if ($referencedTable && Schema::hasTable($referencedTable)) {
                                            try {
                                                // Determine ON DELETE action (default to RESTRICT)
                                                $onDelete = 'RESTRICT';
                                                if ($table === 'activity_group' && $column === 'plan') {
                                                    $onDelete = 'CASCADE';
                                                } elseif ($table === 'activity' && $column === 'activity_group') {
                                                    $onDelete = 'CASCADE';
                                                } elseif ($table === 'plan_param_value' && $column === 'plan') {
                                                    $onDelete = 'CASCADE';
                                                } elseif ($table === 'plan_extra_block' && $column === 'plan') {
                                                    $onDelete = 'CASCADE';
                                                } elseif ($table === 'extra_block' && $column === 'plan') {
                                                    $onDelete = 'CASCADE';
                                                } elseif ($table === 'team_plan' && $column === 'plan') {
                                                    $onDelete = 'CASCADE';
                                                } elseif ($table === 'match' && $column === 'plan') {
                                                    $onDelete = 'CASCADE';
                                                } elseif ($table === 's_generator' && $column === 'plan') {
                                                    $onDelete = 'CASCADE';
                                                } elseif ($table === 'publication' && $column === 'event') {
                                                    $onDelete = 'CASCADE';
                                                } elseif ($table === 'slide' && $column === 'slideshow') {
                                                    $onDelete = 'CASCADE';
                                                } elseif ($table === 'news_user' && $column === 'news_id') {
                                                    $onDelete = 'CASCADE';
                                                } elseif ($table === 'news_user' && $column === 'user_id') {
                                                    $onDelete = 'CASCADE';
                                                } elseif ($table === 'room_type_room' && $column === 'room') {
                                                    $onDelete = 'CASCADE';
                                                } elseif ($table === 'team' && $column === 'room') {
                                                    $onDelete = 'SET NULL';
                                                } elseif ($table === 'activity' && $column === 'plan_extra_block') {
                                                    $onDelete = 'SET NULL';
                                                } elseif ($table === 'activity' && $column === 'extra_block') {
                                                    $onDelete = 'SET NULL';
                                                } elseif ($table === 'event' && $column === 'regional_partner') {
                                                    $onDelete = 'SET NULL';
                                                } elseif ($table === 'logo' && $column === 'event') {
                                                    $onDelete = 'SET NULL';
                                                } elseif ($table === 'logo' && $column === 'regional_partner') {
                                                    $onDelete = 'SET NULL';
                                                }
                                                
                                                $fkName = "{$table}_{$column}_foreign";
                                                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`{$column}`) REFERENCES `{$referencedTable}` (`{$referencedColumn}`) ON DELETE {$onDelete}");
                                                echo "    ✓ Re-added foreign key on {$table}.{$column}\n";
                                            } catch (\Throwable $e) {
                                                echo "    ⚠️  Could not re-add FK on {$table}.{$column}: " . $e->getMessage() . "\n";
                                            }
                                        }
                                        
                                        echo "  ✓ Updated {$table}.{$column} to INT(10)\n";
                                    }
                                }
                            } catch (\Throwable $e) {
                                echo "  ⚠️  Failed to update {$table}.{$column}: " . $e->getMessage() . "\n";
                            }
                        }
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
        // Note: We don't reverse this migration as it's a one-way schema update
        // Reverting would require knowing the original column types, which is complex
    }
};
