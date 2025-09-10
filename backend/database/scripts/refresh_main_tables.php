<?php

/**
 * Script to refresh main tables from dev database
 * 
 * This script should be run after the comprehensive migration
 * to refresh main table data from the dev database.
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/refresh_main_tables.php';
 * >>> refreshMainTables();
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function refreshMainTables()
{
    echo "Starting main table refresh...\n";
    
    // List of main tables that should be refreshed
    $mainTables = [
        'm_activity_type',
        'm_activity_type_detail', 
        'm_first_program',
        'm_insert_point',
        'm_level',
        'm_parameter',
        'm_role',
        'm_room_type',
        'm_room_type_group',
        'm_season',
        'm_supported_plan',
        'm_visibility'
    ];
    
    foreach ($mainTables as $table) {
        if (Schema::hasTable($table)) {
            echo "Refreshing table: {$table}\n";
            
            // Disable foreign key checks temporarily
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            
            // Truncate the table
            DB::table($table)->truncate();
            
            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            
            echo "✓ Table {$table} truncated\n";
        } else {
            echo "⚠ Table {$table} does not exist, skipping\n";
        }
    }
    
    echo "\nMain table refresh completed!\n";
    echo "Next steps:\n";
    echo "1. Copy fresh data from dev database to these tables\n";
    echo "2. Run: php artisan db:seed --class=MainDataSeeder (if you have one)\n";
    echo "3. Or manually import the main table data from dev\n";
}

// If running directly, execute the function
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    refreshMainTables();
}
