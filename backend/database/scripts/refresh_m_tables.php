<?php

/**
 * Script to drop and recreate only m_ tables (master tables)
 * This preserves data in other tables while refreshing master data from the repo
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function refreshMTables(): void
{
    echo "🔄 Refreshing m_ tables (master tables)...\n";
    
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
    
    if (empty($mTableNames)) {
        echo "⚠️  No m_ tables found to refresh.\n";
        return;
    }
    
    echo "Found " . count($mTableNames) . " m_ tables to refresh:\n";
    foreach ($mTableNames as $table) {
        echo "  - $table\n";
    }
    
    // Disable foreign key checks
    DB::statement('SET FOREIGN_KEY_CHECKS=0;');
    
    try {
        // Drop all m_ tables
        echo "\n🗑️  Dropping m_ tables...\n";
        foreach ($mTableNames as $table) {
            Schema::dropIfExists($table);
            echo "  ✓ Dropped $table\n";
        }
        
        echo "\n✅ All m_ tables dropped successfully.\n";
    } finally {
        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }
    
    echo "\n💡 Next steps:\n";
    echo "  1. Run migrations to recreate m_ tables: php artisan migrate --force\n";
    echo "  2. Run MainDataSeeder to populate m_ tables: php artisan db:seed --class=MainDataSeeder --force\n";
}

