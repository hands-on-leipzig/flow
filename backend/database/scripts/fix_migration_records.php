<?php

/**
 * Script to fix migration records by detecting which tables exist
 * and marking their migrations as run in the migrations table
 * 
 * Run this on production: php artisan tinker
 * >>> include 'database/scripts/fix_migration_records.php';
 * >>> fixMigrationRecords();
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function fixMigrationRecords(): void
{
    echo "🔧 Fixing Migration Records\n";
    echo str_repeat("=", 50) . "\n\n";
    
    // Map tables to their migrations
    // NOTE: 
    // - m_ tables are NOT included here because they get dropped and recreated by refresh_m_tables.php
    // - create_master_tables is NOT included here because it creates BOTH m_ tables AND non-m_ tables.
    //   Since refresh_m_tables.php will remove its migration record and we only drop m_ tables,
    //   the non-m_ tables still exist, so we can't mark this migration as run.
    //   The migration will be re-run, but it will use Schema::create() which should handle existing tables
    //   gracefully (or we need to use Schema::createIfNotExists() - but that's a migration change).
    $tableToMigration = [
        // Other migrations (NOT create_master_tables - that's handled by refresh_m_tables.php)
        's_generator' => '2025_09_10_061841_create_s_generator_table',
        // news table (was m_news) is now a regular table, not a master table
        'news_user' => '2025_10_21_120956_create_news_user_table',
        'match' => '2025_10_14_042537_create_match_table',
        'contao_public_rounds' => '2025_10_26_100550_contao_round_publish_flags',
        'jobs' => '2025_08_14_063522_create_jobs_table',
        'cache' => '2025_10_13_151117_create_cache_table',
        'cache_locks' => '2025_10_13_151117_create_cache_table',
        'failed_jobs' => '2025_10_13_151148_create_failed_jobs_table',
    ];
    
    // Get all existing tables
    $dbName = DB::connection()->getDatabaseName();
    $tables = DB::select("SHOW TABLES");
    $tableKey = "Tables_in_{$dbName}";
    
    $existingTables = [];
    foreach ($tables as $table) {
        $existingTables[] = $table->$tableKey;
    }
    
    echo "Found " . count($existingTables) . " existing tables\n\n";
    
    // Group tables by migration
    $migrationsToMark = [];
    foreach ($tableToMigration as $table => $migration) {
        if (in_array($table, $existingTables)) {
            if (!isset($migrationsToMark[$migration])) {
                $migrationsToMark[$migration] = [];
            }
            $migrationsToMark[$migration][] = $table;
        }
    }
    
    echo "Migrations that need to be marked as run:\n";
    foreach ($migrationsToMark as $migration => $tables) {
        echo "  - $migration (" . count($tables) . " tables)\n";
    }
    
    echo "\n";
    
    // Ensure migrations table exists
    if (!Schema::hasTable('migrations')) {
        echo "Creating migrations table...\n";
        Schema::create('migrations', function ($table) {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });
        echo "✓ migrations table created\n\n";
    }
    
    // Get current max batch number
    $maxBatch = DB::table('migrations')->max('batch') ?? 0;
    $nextBatch = $maxBatch + 1;
    
    echo "Current max batch: $maxBatch\n";
    echo "Will use batch: $nextBatch\n\n";
    
    // Insert migration records
    $inserted = 0;
    foreach ($migrationsToMark as $migration => $tables) {
        // Check if migration record already exists
        $exists = DB::table('migrations')
            ->where('migration', $migration)
            ->exists();
        
        if (!$exists) {
            DB::table('migrations')->insert([
                'migration' => $migration,
                'batch' => $nextBatch,
            ]);
            echo "✓ Marked as run: $migration\n";
            $inserted++;
        } else {
            echo "  Already exists: $migration\n";
        }
    }
    
    echo "\n";
    echo "Inserted $inserted migration record(s)\n";
    echo "\n";
    
    // Show current migration status
    $totalRecords = DB::table('migrations')->count();
    echo "Total migration records: $totalRecords\n";
    echo "\n";
    echo "Now you can run: php artisan migrate --force\n";
    echo "This will only run migrations that haven't been marked as run.\n";
    echo "\n";
}

