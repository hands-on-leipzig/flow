<?php

/**
 * Diagnostic script to check migration status and test migrations
 * Run this directly on production: php artisan tinker
 * >>> include 'database/scripts/check_migrations.php';
 * >>> checkMigrations();
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;

function checkMigrations(): void
{
    echo "🔍 Migration Diagnostic Tool\n";
    echo str_repeat("=", 50) . "\n\n";
    
    // 1. Check if migrations table exists
    echo "1. Checking migrations table...\n";
    if (Schema::hasTable('migrations')) {
        echo "   ✓ migrations table exists\n";
        
        $count = DB::table('migrations')->count();
        echo "   Migration records: $count\n";
        
        if ($count > 0) {
            echo "\n   Recent migrations:\n";
            $recent = DB::table('migrations')
                ->orderBy('id', 'desc')
                ->limit(10)
                ->get();
            
            foreach ($recent as $migration) {
                echo "     - {$migration->migration} (batch: {$migration->batch})\n";
            }
        } else {
            echo "   ⚠️  WARNING: migrations table is empty!\n";
        }
    } else {
        echo "   ❌ migrations table does NOT exist\n";
        echo "   This means migrations have never been run.\n";
    }
    
    echo "\n";
    
    // 2. Check migration files
    echo "2. Checking migration files...\n";
    $migrationPath = database_path('migrations');
    if (is_dir($migrationPath)) {
        $files = glob($migrationPath . '/*.php');
        $count = count($files);
        echo "   ✓ Migration directory exists: $migrationPath\n";
        echo "   Migration files found: $count\n";
        
        if ($count > 0) {
            echo "\n   Sample migration files:\n";
            $sample = array_slice($files, 0, 5);
            foreach ($sample as $file) {
                echo "     - " . basename($file) . "\n";
            }
        }
    } else {
        echo "   ❌ Migration directory does NOT exist: $migrationPath\n";
    }
    
    echo "\n";
    
    // 3. Check database connection
    echo "3. Checking database connection...\n";
    try {
        $dbName = DB::connection()->getDatabaseName();
        echo "   ✓ Connected to database: $dbName\n";
        
        // Check if we can query
        $tables = DB::select("SHOW TABLES");
        $tableKey = "Tables_in_{$dbName}";
        $tableCount = count($tables);
        echo "   Tables in database: $tableCount\n";
    } catch (\Exception $e) {
        echo "   ❌ Database connection failed: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
    
    // 4. Check for missing tables that should exist
    echo "4. Checking for missing tables...\n";
    $requiredTables = [
        's_generator',
        'm_news',
        'news_user',
        'match',
        'contao_public_rounds',
        'jobs',
        'cache',
        'cache_locks',
        'failed_jobs',
        'migrations',
    ];
    
    $missing = [];
    foreach ($requiredTables as $table) {
        if (!Schema::hasTable($table)) {
            $missing[] = $table;
            echo "   ⚠️  Missing: $table\n";
        }
    }
    
    if (empty($missing)) {
        echo "   ✓ All required tables exist\n";
    } else {
        echo "   Found " . count($missing) . " missing table(s)\n";
    }
    
    echo "\n";
    
    // 5. Test running migrate:status
    echo "5. Testing migrate:status command...\n";
    try {
        ob_start();
        Artisan::call('migrate:status');
        $output = ob_get_clean();
        echo "   Command output:\n";
        echo "   " . str_replace("\n", "\n   ", trim($output)) . "\n";
    } catch (\Exception $e) {
        echo "   ❌ migrate:status failed: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
    
    // 6. Count all tables and compare with expected
    echo "6. Table count analysis...\n";
    try {
        $tables = DB::select("SHOW TABLES");
        $tableKey = "Tables_in_{$dbName}";
        $allTables = [];
        foreach ($tables as $table) {
            $allTables[] = $table->$tableKey;
        }
        
        $tableCount = count($allTables);
        echo "   Current table count: $tableCount\n";
        echo "   Expected: ~45 tables (dev/test have 45)\n";
        
        if ($tableCount < 40) {
            echo "   ⚠️  WARNING: Table count is too low! Migrations may not have run.\n";
        }
        
        echo "\n   All tables in database:\n";
        sort($allTables);
        foreach ($allTables as $table) {
            echo "     - $table\n";
        }
    } catch (\Exception $e) {
        echo "   ❌ Failed to list tables: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
    echo str_repeat("=", 50) . "\n";
    echo "Diagnostic complete.\n";
    echo "\n";
    echo "To run migrations manually, use:\n";
    echo "  php artisan migrate --force\n";
    echo "\n";
}

