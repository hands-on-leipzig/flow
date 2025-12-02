<?php

/**
 * Check for Extra Tables
 * 
 * Finds tables that exist in the current database but are NOT in the master migration.
 * These are typically Laravel system tables (cache, jobs, migrations) or legacy tables.
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/check_extra_tables.php';
 * >>> checkExtraTables('dev');  // or 'test', 'prod'
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function checkExtraTables($environment = null)
{
    $dbName = DB::connection()->getDatabaseName();
    $env = $environment ?: (str_contains($dbName, 'test') ? 'test' : (str_contains($dbName, 'prod') ? 'prod' : 'dev'));
    
    echo "🔍 Checking for Extra Tables (in DB but NOT in Master Migration)\n";
    echo "Environment: {$env}\n";
    echo "=====================================\n\n";

    // Get tables from current database
    $prodTables = DB::select('SHOW TABLES');
    $tableKey = 'Tables_in_' . $dbName;
    $dbTableList = [];
    foreach ($prodTables as $row) {
        $dbTableList[] = $row->$tableKey;
    }
    sort($dbTableList);

    // Get tables from master migration
    $masterPath = base_path('database/migrations/2025_01_01_000000_create_master_tables.php');
    if (!file_exists($masterPath)) {
        echo "❌ Master migration not found: {$masterPath}\n";
        return [];
    }
    
    $masterContent = file_get_contents($masterPath);
    preg_match_all("/Schema::(?:create|table)\s*\(['\"](\w+)['\"]/i", $masterContent, $matches);
    $masterTableList = array_unique($matches[1]);
    sort($masterTableList);

    // Find tables in DB but not in master
    $extraTables = array_diff($dbTableList, $masterTableList);

    echo "📊 Statistics:\n";
    echo "  Database tables: " . count($dbTableList) . "\n";
    echo "  Master migration tables: " . count($masterTableList) . "\n";
    echo "  Extra tables in database: " . count($extraTables) . "\n\n";

    if (count($extraTables) > 0) {
        echo "⚠️  Tables in Database but NOT in Master Migration:\n";
        $extraTableInfo = [];
        foreach ($extraTables as $table) {
            $rowCount = DB::table($table)->count();
            $extraTableInfo[] = [
                'name' => $table,
                'rows' => $rowCount,
                'type' => classifyTable($table)
            ];
            echo "  - {$table} ({$rowCount} rows) [" . classifyTable($table) . "]\n";
        }
        
        echo "\n📋 Classification:\n";
        $laravelTables = array_filter($extraTableInfo, fn($t) => $t['type'] === 'Laravel System');
        $legacyTables = array_filter($extraTableInfo, fn($t) => $t['type'] === 'Legacy/Unknown');
        
        if (count($laravelTables) > 0) {
            echo "  Laravel System Tables (usually safe to keep):\n";
            foreach ($laravelTables as $table) {
                echo "    - {$table['name']}\n";
            }
        }
        
        if (count($legacyTables) > 0) {
            echo "  Legacy/Unknown Tables (review needed):\n";
            foreach ($legacyTables as $table) {
                echo "    - {$table['name']}\n";
            }
        }
    } else {
        echo "✅ No extra tables found - all database tables are in master migration\n";
    }
    
    return $extraTableInfo ?? [];
}

function classifyTable($tableName)
{
    $laravelSystemTables = [
        'cache', 'cache_locks', 'failed_jobs', 'jobs', 'migrations',
        'password_reset_tokens', 'sessions', 'personal_access_tokens'
    ];
    
    if (in_array($tableName, $laravelSystemTables)) {
        return 'Laravel System';
    }
    
    return 'Legacy/Unknown';
}

