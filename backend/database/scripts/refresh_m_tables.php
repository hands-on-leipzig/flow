<?php

/**
 * Script to drop and recreate only m_ tables (master tables)
 * This preserves data in other tables while refreshing master data from the repo
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove migration records for tables that should exist but don't
 * This ensures migrations will be re-run to create missing tables
 */
function fixMissingTables(): void
{
    echo "🔍 Checking for missing tables...\n";
    
    // List of migrations that create tables that should always exist
    $requiredTableMigrations = [
        '2025_09_10_061841_create_s_generator_table' => 's_generator',
        '2025_10_14_042537_create_match_table' => 'match',
        '2025_10_26_100550_contao_round_publish_flags' => 'contao_public_rounds',
        '2025_08_14_063522_create_jobs_table' => 'jobs',
        '2025_10_13_151117_create_cache_table' => ['cache', 'cache_locks'], // Creates both tables
        '2025_10_13_151148_create_failed_jobs_table' => 'failed_jobs',
        '2025_10_21_120956_create_news_user_table' => 'news_user',
        '2025_01_01_000000_create_master_tables' => ['slideshow', 'slide', 'publication', 'm_parameter_condition', 'q_plan', 'q_plan_match', 'q_plan_team', 'q_run'],
    ];
    
    foreach ($requiredTableMigrations as $migration => $tables) {
        $tableList = is_array($tables) ? $tables : [$tables];
        $allTablesExist = true;
        
        foreach ($tableList as $table) {
            if (!Schema::hasTable($table)) {
                $allTablesExist = false;
                echo "  ⚠️  Table '{$table}' is missing (migration: {$migration})\n";
                break;
            }
        }
        
        if (!$allTablesExist) {
            // Remove migration record so it will be re-run
            $deleted = DB::table('migrations')
                ->where('migration', $migration)
                ->delete();
            
            if ($deleted > 0) {
                echo "    ✓ Removed migration record for {$migration} (will be re-run)\n";
            }
        }
    }
}

function refreshMTables(): void
{
    echo "🔄 Refreshing m_ tables (master tables)...\n";
    
    // First, fix any missing non-m_ tables
    fixMissingTables();
    
    // Get all tables from the database
    $databaseName = DB::connection()->getDatabaseName();
    $tables = DB::select("SHOW TABLES");
    $tableKey = "Tables_in_{$databaseName}";
    
    $mTableNames = [];
    
    // Find all tables that start with 'm_'
    foreach ($tables as $table) {
        $tableName = $table->$tableKey;
        if (str_starts_with($tableName, 'm_')) {
            $mTableNames[] = $tableName;
        }
    }
    
    // Always remove migration records for all m_ table migrations so they will be re-run
    // This allows migrate --force to recreate the m_ tables, even if they don't exist yet
    $mTableMigrations = [
        '2025_01_01_000000_create_master_tables', // Main master tables migration (creates 13 m_ tables)
        '2025_08_05_151326_add_disable_option_to_visibility_enum', // Modifies m_parameter_condition
        '2025_10_14_124139_modify_m_insert_point_table', // Modifies m_insert_point
        '2025_10_21_120706_create_m_news_table', // m_news table migration
        '2025_11_08_230638_update_m_activity_type_overview_plan_column_not_null', // Modifies m_activity_type
        '2025_11_08_230644_add_jury_rounds_to_m_supported_plan', // Modifies m_supported_plan
        // Add any other m_ table migrations here as needed
    ];
    
    echo "  🔄 Removing migration records to force re-run...\n";
    foreach ($mTableMigrations as $migration) {
        $deleted = DB::table('migrations')
            ->where('migration', $migration)
            ->delete();
        
        if ($deleted > 0) {
            echo "    ✓ Removed migration record for {$migration} (will be re-run)\n";
        } else {
            echo "    ⚠️  Migration record for {$migration} not found (may not exist yet)\n";
        }
    }
    
    if (empty($mTableNames)) {
        echo "⚠️  No m_ tables found to drop (they will be created by migration).\n";
        echo "\n✅ Migration record removed. Ready for migration.\n";
        return;
    }
    
    echo "Found " . count($mTableNames) . " m_ tables to refresh:\n";
    foreach ($mTableNames as $table) {
        echo "  - $table\n";
    }
    
    // Disable foreign key checks before dropping
    $driver = DB::connection()->getDriverName();
    if ($driver === 'mysql' || $driver === 'mariadb') {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
    } elseif ($driver === 'sqlite') {
        DB::statement('PRAGMA foreign_keys = OFF;');
    }
    
    try {
        // Drop foreign keys that reference m_ tables before dropping the m_ tables themselves
        // This prevents type mismatch errors when recreating m_ tables with different column types
        echo "\n🔗 Dropping foreign keys that reference m_ tables...\n";
        
        // Drop foreign key from news_user to m_news if it exists
        if (Schema::hasTable('news_user')) {
            try {
                $foreignKeys = DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM information_schema.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = ? 
                    AND TABLE_NAME = 'news_user' 
                    AND REFERENCED_TABLE_NAME = 'm_news'
                ", [DB::connection()->getDatabaseName()]);
                
                foreach ($foreignKeys as $fk) {
                    DB::statement("ALTER TABLE `news_user` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    echo "  ✓ Dropped foreign key {$fk->CONSTRAINT_NAME} from news_user\n";
                }
            } catch (\Throwable $e) {
                // Ignore if foreign key doesn't exist or can't be dropped
            }
        }
        
        // Drop all m_ tables
        echo "\n🗑️  Dropping m_ tables...\n";
        foreach ($mTableNames as $table) {
            Schema::dropIfExists($table);
            echo "  ✓ Dropped $table\n";
        }
        
        echo "\n✅ All m_ tables dropped successfully.\n";
    } finally {
        // Re-enable foreign key checks
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }
    }
    
    echo "\n💡 Next steps:\n";
    echo "  1. Run migrations to recreate m_ tables: php artisan migrate --force\n";
    echo "  2. Run MainDataSeeder to populate m_ tables: php artisan db:seed --class=MainDataSeeder --force\n";
}

