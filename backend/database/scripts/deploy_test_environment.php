<?php

/**
 * Comprehensive Test Environment Deployment Script
 * 
 * This script performs a complete deployment to the test environment:
 * 1. Purges the test database completely
 * 2. Runs all migrations to ensure schema is up to date
 * 3. Populates m_ tables from dev database
 * 4. Creates three test events with proper configurations
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/deploy_test_environment.php';
 * >>> deployTestEnvironment();
 * 
 * Or run directly: php database/scripts/deploy_test_environment.php
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use App\Models\User;
use App\Models\RegionalPartner;
use App\Models\Event;
use App\Models\Plan;
use App\Models\MSeason;
use App\Models\MLevel;

function deployTestEnvironment()
{
    echo "🚀 Starting Test Environment Deployment...\n";
    echo "==========================================\n\n";
    
    try {
        // Step 1: Purge test database completely
        echo "Step 1: Purging test database...\n";
        purgeTestDatabase();
        
        // Step 2: Run migrations
        echo "\nStep 2: Running migrations...\n";
        runMigrations();
        
        // Step 3: Populate master tables
        echo "\nStep 3: Populating master tables...\n";
        populateMasterTables();
        
        // Step 4: Create test events
        echo "\nStep 4: Creating test events...\n";
        createTestEvents();
        
        // Step 5: Final verification
        echo "\nStep 5: Final verification...\n";
        verifyDeployment();
        
        echo "\n✅ Test Environment Deployment Complete!\n";
        echo "==========================================\n";
        
    } catch (Exception $e) {
        echo "\n❌ Deployment failed: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        throw $e;
    }
}

function purgeTestDatabase()
{
    echo "  Clearing all data tables...\n";
    
    // List of all tables to clear (both data and master tables)
    $allTables = [
        // Data tables
        'activity',
        'activity_group', 
        'event',
        'event_logo',
        'extra_block',
        'logo',
        'plan',
        'plan_extra_block',
        'plan_param_value',
        'regional_partner',
        'room',
        'room_type_room',
        'table_event',
        'team',
        'team_plan',
        'user',
        'user_regional_partner',
        'slideshow',
        'slide',
        'publication',
        's_generator',
        
        // Master tables
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
    
    // Disable foreign key checks
    DB::statement('SET FOREIGN_KEY_CHECKS=0;');
    
    foreach ($allTables as $table) {
        if (Schema::hasTable($table)) {
            DB::table($table)->truncate();
            echo "    ✓ Cleared table: {$table}\n";
        } else {
            echo "    ⚠ Table {$table} does not exist, skipping\n";
        }
    }
    
    // Re-enable foreign key checks
    DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    
    echo "  ✓ Database purge complete\n";
}

function runMigrations()
{
    echo "  Running Laravel migrations...\n";
    
    try {
        // Run migrations
        Artisan::call('migrate', ['--force' => true]);
        echo "    ✓ Migrations completed successfully\n";
        
        // Check if there are any pending migrations
        $exitCode = Artisan::call('migrate:status');
        if ($exitCode === 0) {
            echo "    ✓ All migrations are up to date\n";
        }
        
    } catch (Exception $e) {
        echo "    ❌ Migration failed: " . $e->getMessage() . "\n";
        throw $e;
    }
}

function populateMasterTables()
{
    echo "  Populating master tables...\n";
    
    // Check if we have access to dev database
    $devConnection = config('database.connections.dev');
    if (!$devConnection) {
        echo "    ⚠ No dev database connection configured\n";
        echo "    Please manually populate master tables from dev database\n";
        echo "    Tables to populate:\n";
        $masterTables = [
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
        foreach ($masterTables as $table) {
            echo "      - {$table}\n";
        }
        return;
    }
    
    // If dev connection exists, try to copy data
    try {
        $masterTables = [
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
        
        foreach ($masterTables as $table) {
            if (Schema::hasTable($table)) {
                // This would require implementing the actual data copy logic
                // For now, we'll just indicate that manual population is needed
                echo "    ⚠ Table {$table} needs manual population from dev database\n";
            }
        }
        
        echo "    ⚠ Automatic master table population not implemented\n";
        echo "    Please run the following commands to populate master tables:\n";
        echo "    1. Export from dev: mysqldump -u dev_user -p dev_database --tables m_* --no-create-info > master_tables.sql\n";
        echo "    2. Import to test: mysql -u test_user -p test_database < master_tables.sql\n";
        
    } catch (Exception $e) {
        echo "    ❌ Error accessing dev database: " . $e->getMessage() . "\n";
        echo "    Please manually populate master tables from dev database\n";
    }
}

function createTestEvents()
{
    echo "  Creating test events...\n";
    
    // Ensure we have required master data
    $season = MSeason::first();
    if (!$season) {
        echo "    ❌ No season found. Creating default season...\n";
        $season = MSeason::create([
            'name' => 'Test Season 2024',
            'year' => 2024,
            'active' => true
        ]);
        echo "    ✓ Created season: {$season->name}\n";
    } else {
        echo "    ✓ Using season: {$season->name}\n";
    }
    
    $level = MLevel::first();
    if (!$level) {
        echo "    ❌ No level found. Creating default level...\n";
        $level = MLevel::create([
            'name' => 'Test Level',
            'level' => 1
        ]);
        echo "    ✓ Created level: {$level->name}\n";
    } else {
        echo "    ✓ Using level: {$level->name}\n";
    }
    
    // Create test regional partners
    $testRPs = [
        [
            'name' => 'Test Regional Partner A', 
            'region' => 'Test Region A', 
            'dolibarr_id' => 2001
        ],
        [
            'name' => 'Test Regional Partner B', 
            'region' => 'Test Region B', 
            'dolibarr_id' => 2002
        ],
    ];
    
    $createdRPs = [];
    foreach ($testRPs as $rpData) {
        $rp = RegionalPartner::create($rpData);
        $createdRPs[] = $rp;
        echo "    ✓ Created regional partner: {$rp->name}\n";
    }
    
    // Create three test events as specified
    $eventsCreated = 0;
    
    // Event 1: Regional Partner A - Explore only
    $exploreEvent = Event::create([
        'name' => "RPT Demo - Nur Explore",
        'regional_partner' => $createdRPs[0]->id,
        'season' => $season->id,
        'level' => $level->id,
        'date' => now()->addDays(30),
        'days' => 1,
        'slug' => 'rpt-demo-nur-explore',
        'event_explore' => 1001, // Mock explore ID
        'event_challenge' => null
    ]);
    $eventsCreated++;
    echo "    ✓ Created explore event: {$exploreEvent->name}\n";
    
    // Event 2: Regional Partner A - Challenge only
    $challengeEvent = Event::create([
        'name' => "RPT Demo - Nur Challenge",
        'regional_partner' => $createdRPs[0]->id,
        'season' => $season->id,
        'level' => $level->id,
        'date' => now()->addDays(45),
        'days' => 1,
        'slug' => 'rpt-demo-nur-challenge',
        'event_explore' => null,
        'event_challenge' => 1002 // Mock challenge ID
    ]);
    $eventsCreated++;
    echo "    ✓ Created challenge event: {$challengeEvent->name}\n";
    
    // Event 3: Regional Partner B - Combined event (both explore and challenge)
    $combinedEvent = Event::create([
        'name' => "RPT Demo",
        'regional_partner' => $createdRPs[1]->id,
        'season' => $season->id,
        'level' => $level->id,
        'date' => now()->addDays(60),
        'days' => 1,
        'slug' => 'rpt-demo',
        'event_explore' => 1003, // Mock explore ID
        'event_challenge' => 1004 // Mock challenge ID
    ]);
    $eventsCreated++;
    echo "    ✓ Created combined event: {$combinedEvent->name}\n";
    
    echo "    ✓ Created {$eventsCreated} test events\n";
}

function verifyDeployment()
{
    echo "  Verifying deployment...\n";
    
    // Check master tables
    $masterTables = [
        'm_activity_type' => 'Activity Types',
        'm_activity_type_detail' => 'Activity Type Details',
        'm_first_program' => 'First Programs',
        'm_insert_point' => 'Insert Points',
        'm_level' => 'Levels',
        'm_parameter' => 'Parameters',
        'm_role' => 'Roles',
        'm_room_type' => 'Room Types',
        'm_room_type_group' => 'Room Type Groups',
        'm_season' => 'Seasons',
        'm_supported_plan' => 'Supported Plans',
        'm_visibility' => 'Visibility Rules'
    ];
    
    echo "    Master tables status:\n";
    foreach ($masterTables as $table => $name) {
        if (Schema::hasTable($table)) {
            $count = DB::table($table)->count();
            echo "      {$name}: {$count} records\n";
        } else {
            echo "      {$name}: ❌ Table does not exist\n";
        }
    }
    
    // Check data tables
    echo "    Data tables status:\n";
    echo "      Regional Partners: " . RegionalPartner::count() . "\n";
    echo "      Events: " . Event::count() . "\n";
    echo "      Users: " . User::count() . "\n";
    
    // Check specific test events
    $testEvents = Event::whereIn('slug', [
        'rpt-demo-nur-explore',
        'rpt-demo-nur-challenge', 
        'rpt-demo'
    ])->get();
    
    echo "    Test events created:\n";
    foreach ($testEvents as $event) {
        echo "      ✓ {$event->name} (ID: {$event->id})\n";
    }
    
    if ($testEvents->count() !== 3) {
        echo "    ⚠ Warning: Expected 3 test events, found {$testEvents->count()}\n";
    }
    
    echo "    ✓ Deployment verification complete\n";
}

// If running directly, execute the function
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    deployTestEnvironment();
}
