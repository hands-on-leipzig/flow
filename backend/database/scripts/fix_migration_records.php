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
    // NOTE: m_ tables are NOT included here because they get dropped and recreated
    // by refresh_m_tables.php, so their migrations should always run
    $tableToMigration = [
        // From create_master_tables (excluding m_ tables - those are handled separately)
        'regional_partner' => '2025_01_01_000000_create_master_tables',
        'event' => '2025_01_01_000000_create_master_tables',
        'slideshow' => '2025_01_01_000000_create_master_tables',
        'slide' => '2025_01_01_000000_create_master_tables',
        'publication' => '2025_01_01_000000_create_master_tables',
        'user' => '2025_01_01_000000_create_master_tables',
        'user_regional_partner' => '2025_01_01_000000_create_master_tables',
        'room' => '2025_01_01_000000_create_master_tables',
        'room_type_room' => '2025_01_01_000000_create_master_tables',
        'team' => '2025_01_01_000000_create_master_tables',
        'plan' => '2025_01_01_000000_create_master_tables',
        'team_plan' => '2025_01_01_000000_create_master_tables',
        'plan_param_value' => '2025_01_01_000000_create_master_tables',
        'extra_block' => '2025_01_01_000000_create_master_tables',
        'plan_extra_block' => '2025_01_01_000000_create_master_tables',
        'activity_group' => '2025_01_01_000000_create_master_tables',
        'activity' => '2025_01_01_000000_create_master_tables',
        'logo' => '2025_01_01_000000_create_master_tables',
        'event_logo' => '2025_01_01_000000_create_master_tables',
        'table_event' => '2025_01_01_000000_create_master_tables',
        'q_plan' => '2025_01_01_000000_create_master_tables',
        'q_plan_match' => '2025_01_01_000000_create_master_tables',
        'q_plan_team' => '2025_01_01_000000_create_master_tables',
        'q_run' => '2025_01_01_000000_create_master_tables',
        
        // Other migrations
        's_generator' => '2025_09_10_061841_create_s_generator_table',
        // m_news is handled by refresh_m_tables.php, don't mark it here
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

