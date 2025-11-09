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
    
    // Always remove migration records for all m_ table migrations so they will be re-run
    // This allows migrate --force to recreate the m_ tables, even if they don't exist yet
    $mTableMigrations = [
        '2025_01_01_000000_create_master_tables', // Main master tables migration (creates 13 m_ tables)
        '2025_10_21_120706_create_m_news_table', // m_news table migration
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

