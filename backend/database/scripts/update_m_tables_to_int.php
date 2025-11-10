<?php

/**
 * Script to update all m_ table IDs and their foreign keys from bigint(20) to int(10)
 * Run this directly on the dev server: php artisan tinker --execute="include 'database/scripts/update_m_tables_to_int.php'; updateMTablesToInt();"
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function updateMTablesToInt(): void
{
    echo "🔄 Updating m_ tables to use int(10) for IDs...\n";
    
    $driver = DB::connection()->getDriverName();
    
    // Disable foreign key checks
    if ($driver === 'mysql' || $driver === 'mariadb') {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
    } elseif ($driver === 'sqlite') {
        DB::statement('PRAGMA foreign_keys = OFF;');
    }
    
    try {
        // List of all m_ tables
        $mTables = [
            'm_season',
            'm_level',
            'm_room_type_group',
            'm_room_type',
            'm_first_program',
            'm_parameter',
            'm_parameter_condition',
            'm_activity_type',
            'm_activity_type_detail',
            'm_insert_point',
            'm_role',
            'm_visibility',
            'm_supported_plan',
            'm_news',
        ];
        
        // Step 1: Update all foreign key columns first (before m_ tables are recreated)
        // This ensures foreign keys will match when m_ tables are recreated with INT(10)
        echo "\n📝 Step 1: Updating foreign key columns to INT(10)...\n";
        
        // (Already updated above)
        
        // event table
        if (Schema::hasTable('event')) {
            try {
                // Drop foreign keys
                try {
                    DB::statement("ALTER TABLE `event` DROP FOREIGN KEY `event_level_foreign`");
                } catch (\Throwable $e) {
                    // Try alternative FK name
                    try {
                        $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event' AND REFERENCED_TABLE_NAME = 'm_level'");
                        foreach ($fks as $fk) {
                            DB::statement("ALTER TABLE `event` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                        }
                    } catch (\Throwable $e2) {
                        // Ignore
                    }
                }
                
                try {
                    DB::statement("ALTER TABLE `event` DROP FOREIGN KEY `event_season_foreign`");
                } catch (\Throwable $e) {
                    // Try alternative FK name
                    try {
                        $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event' AND REFERENCED_TABLE_NAME = 'm_season'");
                        foreach ($fks as $fk) {
                            DB::statement("ALTER TABLE `event` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                        }
                    } catch (\Throwable $e2) {
                        // Ignore
                    }
                }
                
                // Update column types (don't re-add foreign keys yet - m_ tables will be recreated)
                if (Schema::hasColumn('event', 'level')) {
                    DB::statement("ALTER TABLE `event` MODIFY COLUMN `level` INT(10) UNSIGNED NOT NULL");
                }
                if (Schema::hasColumn('event', 'season')) {
                    DB::statement("ALTER TABLE `event` MODIFY COLUMN `season` INT(10) UNSIGNED NOT NULL");
                }
                
                echo "  ✓ Updated event.level and event.season to INT(10) (FKs will be added after m_ tables are recreated)\n";
            } catch (\Throwable $e) {
                echo "  ⚠️  Failed to update event table: " . $e->getMessage() . "\n";
            }
        }
        
        // plan table - check if level column exists (it was removed in a migration)
        if (Schema::hasTable('plan') && Schema::hasColumn('plan', 'level')) {
            try {
                // Drop foreign key
                try {
                    $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan' AND REFERENCED_TABLE_NAME = 'm_level'");
                    foreach ($fks as $fk) {
                        DB::statement("ALTER TABLE `plan` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    }
                } catch (\Throwable $e) {
                    // Ignore
                }
                
                // Update column type (don't re-add foreign key yet)
                DB::statement("ALTER TABLE `plan` MODIFY COLUMN `level` INT(10) UNSIGNED NOT NULL");
                
                echo "  ✓ Updated plan.level to INT(10) (FK will be added after m_ tables are recreated)\n";
            } catch (\Throwable $e) {
                echo "  ⚠️  Failed to update plan.level: " . $e->getMessage() . "\n";
            }
        } else {
            echo "  ⚠️  plan.level column does not exist (may have been removed in migration)\n";
        }
        
        // room_type_room table
        if (Schema::hasTable('room_type_room')) {
            try {
                // Drop foreign key
                try {
                    $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_type_room' AND REFERENCED_TABLE_NAME = 'm_room_type'");
                    foreach ($fks as $fk) {
                        DB::statement("ALTER TABLE `room_type_room` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    }
                } catch (\Throwable $e) {
                    // Ignore
                }
                
                // Update column type (don't re-add foreign key yet)
                if (Schema::hasColumn('room_type_room', 'room_type')) {
                    DB::statement("ALTER TABLE `room_type_room` MODIFY COLUMN `room_type` INT(10) UNSIGNED NOT NULL");
                }
                
                echo "  ✓ Updated room_type_room.room_type to INT(10) (FK will be added after m_ tables are recreated)\n";
            } catch (\Throwable $e) {
                echo "  ⚠️  Failed to update room_type_room table: " . $e->getMessage() . "\n";
            }
        }
        
        // Update foreign keys within m_ tables themselves
        echo "\n📝 Updating foreign keys within m_ tables...\n";
        
        // m_room_type
        if (Schema::hasTable('m_room_type')) {
            try {
                // Drop foreign keys
                try {
                    $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'm_room_type' AND REFERENCED_TABLE_NAME = 'm_room_type_group'");
                    foreach ($fks as $fk) {
                        DB::statement("ALTER TABLE `m_room_type` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    }
                } catch (\Throwable $e) {
                    // Ignore
                }
                
                try {
                    $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'm_room_type' AND REFERENCED_TABLE_NAME = 'm_level'");
                    foreach ($fks as $fk) {
                        DB::statement("ALTER TABLE `m_room_type` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    }
                } catch (\Throwable $e) {
                    // Ignore
                }
                
                // Note: m_room_type will be recreated, so we skip updating it here
                echo "  ⚠️  m_room_type will be recreated with correct types\n";
            } catch (\Throwable $e) {
                echo "  ⚠️  Failed to update m_room_type foreign keys: " . $e->getMessage() . "\n";
            }
        }
        
        // Update other m_ table foreign keys similarly
        $mTableForeignKeys = [
            'm_parameter' => ['level', 'first_program'],
            'm_parameter_condition' => ['parameter', 'if_parameter'],
            'm_activity_type' => ['first_program'],
            'm_activity_type_detail' => ['activity_type', 'first_program'],
            'm_insert_point' => ['first_program', 'level'],
            'm_role' => ['first_program'],
            'm_visibility' => ['activity_type_detail', 'role'],
            'm_supported_plan' => ['first_program'],
        ];
        
        foreach ($mTableForeignKeys as $table => $columns) {
            if (Schema::hasTable($table)) {
                try {
                    // Drop all foreign keys
                    $fks = DB::select("SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND REFERENCED_TABLE_NAME LIKE 'm_%'");
                    foreach ($fks as $fk) {
                        try {
                            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                        } catch (\Throwable $e) {
                            // Ignore
                        }
                    }
                    
                    // Note: m_ tables will be recreated, so we skip updating their foreign keys here
                    echo "  ⚠️  {$table} will be recreated with correct types\n";
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to update {$table} foreign keys: " . $e->getMessage() . "\n";
                }
            }
        }
        
        // Update activity table
        if (Schema::hasTable('activity')) {
            try {
                // Drop foreign keys
                $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity' AND REFERENCED_TABLE_NAME LIKE 'm_%'");
                foreach ($fks as $fk) {
                    try {
                        DB::statement("ALTER TABLE `activity` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    } catch (\Throwable $e) {
                        // Ignore
                    }
                }
                
                // Update column types (don't re-add foreign keys yet)
                if (Schema::hasColumn('activity', 'room_type')) {
                    DB::statement("ALTER TABLE `activity` MODIFY COLUMN `room_type` INT(10) UNSIGNED NULL");
                }
                if (Schema::hasColumn('activity', 'activity_type_detail')) {
                    DB::statement("ALTER TABLE `activity` MODIFY COLUMN `activity_type_detail` INT(10) UNSIGNED NOT NULL");
                }
                
                echo "  ✓ Updated activity foreign keys to INT(10) (FKs will be added after m_ tables are recreated)\n";
            } catch (\Throwable $e) {
                echo "  ⚠️  Failed to update activity table: " . $e->getMessage() . "\n";
            }
        }
        
        // Update activity_group table
        if (Schema::hasTable('activity_group')) {
            try {
                // Drop foreign key
                $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_group' AND REFERENCED_TABLE_NAME = 'm_activity_type_detail'");
                foreach ($fks as $fk) {
                    try {
                        DB::statement("ALTER TABLE `activity_group` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    } catch (\Throwable $e) {
                        // Ignore
                    }
                }
                
                // Update column type (don't re-add foreign key yet)
                if (Schema::hasColumn('activity_group', 'activity_type_detail')) {
                    DB::statement("ALTER TABLE `activity_group` MODIFY COLUMN `activity_type_detail` INT(10) UNSIGNED NOT NULL");
                }
                
                echo "  ✓ Updated activity_group.activity_type_detail to INT(10) (FK will be added after m_ tables are recreated)\n";
            } catch (\Throwable $e) {
                echo "  ⚠️  Failed to update activity_group table: " . $e->getMessage() . "\n";
            }
        }
        
        // Update news_user table
        if (Schema::hasTable('news_user')) {
            try {
                // Drop foreign key
                $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news_user' AND REFERENCED_TABLE_NAME = 'm_news'");
                foreach ($fks as $fk) {
                    try {
                        DB::statement("ALTER TABLE `news_user` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    } catch (\Throwable $e) {
                        // Ignore
                    }
                }
                
                // Update column type (don't re-add foreign key yet)
                if (Schema::hasColumn('news_user', 'news_id')) {
                    DB::statement("ALTER TABLE `news_user` MODIFY COLUMN `news_id` INT(10) UNSIGNED NOT NULL");
                }
                
                echo "  ✓ Updated news_user.news_id to INT(10) (FK will be added after m_ tables are recreated)\n";
            } catch (\Throwable $e) {
                echo "  ⚠️  Failed to update news_user table: " . $e->getMessage() . "\n";
            }
        }
        
        // Update q_plan table - check if level column exists
        if (Schema::hasTable('q_plan') && Schema::hasColumn('q_plan', 'level')) {
            try {
                // Drop foreign key
                $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'q_plan' AND REFERENCED_TABLE_NAME = 'm_level'");
                foreach ($fks as $fk) {
                    try {
                        DB::statement("ALTER TABLE `q_plan` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    } catch (\Throwable $e) {
                        // Ignore
                    }
                }
                
                // Update column type (don't re-add foreign key yet)
                DB::statement("ALTER TABLE `q_plan` MODIFY COLUMN `level` INT(10) UNSIGNED NOT NULL");
                
                echo "  ✓ Updated q_plan.level to INT(10) (FK will be added after m_ tables are recreated)\n";
            } catch (\Throwable $e) {
                echo "  ⚠️  Failed to update q_plan.level: " . $e->getMessage() . "\n";
            }
        } else {
            echo "  ⚠️  q_plan.level column does not exist\n";
        }
        
        // Update other tables that reference m_ tables
        // team.first_program
        if (Schema::hasTable('team') && Schema::hasColumn('team', 'first_program')) {
            try {
                $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'team' AND REFERENCED_TABLE_NAME = 'm_first_program'");
                foreach ($fks as $fk) {
                    try {
                        DB::statement("ALTER TABLE `team` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    } catch (\Throwable $e) {
                        // Ignore
                    }
                }
                
                DB::statement("ALTER TABLE `team` MODIFY COLUMN `first_program` INT(10) UNSIGNED NOT NULL");
                
                echo "  ✓ Updated team.first_program to INT(10) (FK will be added after m_ tables are recreated)\n";
            } catch (\Throwable $e) {
                echo "  ⚠️  Failed to update team.first_program: " . $e->getMessage() . "\n";
            }
        }
        
        // plan.first_program
        if (Schema::hasTable('plan') && Schema::hasColumn('plan', 'first_program')) {
            try {
                $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plan' AND REFERENCED_TABLE_NAME = 'm_first_program'");
                foreach ($fks as $fk) {
                    try {
                        DB::statement("ALTER TABLE `plan` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    } catch (\Throwable $e) {
                        // Ignore
                    }
                }
                
                DB::statement("ALTER TABLE `plan` MODIFY COLUMN `first_program` INT(10) UNSIGNED NOT NULL");
                
                echo "  ✓ Updated plan.first_program to INT(10) (FK will be added after m_ tables are recreated)\n";
            } catch (\Throwable $e) {
                echo "  ⚠️  Failed to update plan.first_program: " . $e->getMessage() . "\n";
            }
        }
        
        // Step 2: Drop and recreate m_ tables with INT(10) IDs
        echo "\n📝 Step 2: Dropping and recreating m_ tables with INT(10) IDs...\n";
        
        // Remove migration records so they will be re-run
        $mTableMigrations = [
            '2025_01_01_000000_create_master_tables',
            '2025_08_05_151326_add_disable_option_to_visibility_enum',
            '2025_10_14_124139_modify_m_insert_point_table',
            '2025_10_21_120706_create_m_news_table',
            '2025_11_08_230638_update_m_activity_type_overview_plan_column_not_null',
            '2025_11_08_230644_add_jury_rounds_to_m_supported_plan',
        ];
        
        foreach ($mTableMigrations as $migration) {
            DB::table('migrations')->where('migration', $migration)->delete();
        }
        
        // Drop all m_ tables
        foreach ($mTables as $table) {
            if (Schema::hasTable($table)) {
                try {
                    Schema::dropIfExists($table);
                    echo "  ✓ Dropped {$table}\n";
                } catch (\Throwable $e) {
                    echo "  ⚠️  Failed to drop {$table}: " . $e->getMessage() . "\n";
                }
            }
        }
        
        echo "\n✅ Foreign key columns updated to INT(10)!\n";
        echo "✅ m_ tables dropped!\n";
        echo "\n⚠️  Next steps:\n";
        echo "   1. Run: php artisan migrate --force (to recreate m_ tables with INT(10) IDs)\n";
        echo "   2. Run: php artisan db:seed --class=MainDataSeeder --force (to populate m_ tables)\n";
        
    } finally {
        // Re-enable foreign key checks
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }
    }
}

