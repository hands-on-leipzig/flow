<?php

/**
 * Script to create a completely fresh test database on each deployment
 * 
 * This script will:
 * 1. Clear all data tables (preserve structure)
 * 2. Refresh main tables with dev data
 * 3. Create fresh test data
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/fresh_test_database.php';
 * >>> freshTestDatabase();
 */

use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\RegionalPartner;
use App\Models\Event;
use App\Models\Plan;
use App\Models\MSeason;
use App\Models\MLevel;

function freshTestDatabase()
{
    echo "Creating fresh test database...\n";
    
    // 1. Clear all data tables (preserve structure)
    echo "Clearing all data tables...\n";
    
    $dataTables = [
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
        's_generator'
    ];
    
    // Disable foreign key checks
    DB::statement('SET FOREIGN_KEY_CHECKS=0;');
    
    foreach ($dataTables as $table) {
        if (DB::getSchemaBuilder()->hasTable($table)) {
            DB::table($table)->truncate();
            echo "  ✓ Cleared table: {$table}\n";
        }
    }
    
    // Re-enable foreign key checks
    DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    
    // 2. Refresh main tables (this will be done by the deployment script)
    echo "✓ Main tables will be refreshed by deployment script\n";
    
    // 3. Create fresh test data
    echo "Creating fresh test data...\n";
    
    // Get the latest season
    $latestSeason = MSeason::latest('year')->first();
    if (!$latestSeason) {
        echo "❌ No season found. Please ensure main tables are populated first.\n";
        return;
    }
    echo "✓ Using season: {$latestSeason->name} (Year: {$latestSeason->year})\n";
    
    // Get a level
    $level = MLevel::first();
    if (!$level) {
        echo "❌ No level found. Please ensure main tables are populated first.\n";
        return;
    }
    echo "✓ Using level: {$level->name}\n";
    
    // Create test regional partners with specific configurations
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
        echo "  ✓ Created regional partner: {$rp->name}\n";
    }
    
    // Create test events with specific configurations
    $eventsCreated = 0;
    
    // Regional Partner A: Two separate events (explore/challenge)
    $rpA = $createdRPs[0];
    
    // Explore event
    $exploreEvent = Event::create([
        'name' => "RPT Demo - Nur Explore",
        'regional_partner' => $rpA->id,
        'season' => $latestSeason->id,
        'level' => $level->id,
        'date' => now()->addDays(30),
        'days' => 1,
        'slug' => 'rpt-demo-nur-explore',
        'event_explore' => 1001, // Mock explore ID
        'event_challenge' => null
    ]);
    $eventsCreated++;
    echo "  ✓ Created explore event: {$exploreEvent->name}\n";
    
    // Challenge event
    $challengeEvent = Event::create([
        'name' => "RPT Demo - Nur Challenge",
        'regional_partner' => $rpA->id,
        'season' => $latestSeason->id,
        'level' => $level->id,
        'date' => now()->addDays(45),
        'days' => 1,
        'slug' => 'rpt-demo-nur-challenge',
        'event_explore' => null,
        'event_challenge' => 1002 // Mock challenge ID
    ]);
    $eventsCreated++;
    echo "  ✓ Created challenge event: {$challengeEvent->name}\n";
    
    // Regional Partner B: Combined event (both explore and challenge on same day)
    $rpB = $createdRPs[1];
    $combinedEvent = Event::create([
        'name' => "RPT Demo",
        'regional_partner' => $rpB->id,
        'season' => $latestSeason->id,
        'level' => $level->id,
        'date' => now()->addDays(60),
        'days' => 1,
        'slug' => 'rpt-demo',
        'event_explore' => 1003, // Mock explore ID
        'event_challenge' => 1004 // Mock challenge ID
    ]);
    $eventsCreated++;
    echo "  ✓ Created combined event: {$combinedEvent->name}\n";
    
    // Note: Test users will be created automatically when they first log in
    // with the 'flow-tester' role. The middleware will auto-assign them
    // to the test regional partners created above.
    echo "  ✓ Test users will be created automatically on first login with 'flow-tester' role\n";
    
    
    // Summary
    echo "\n=== Fresh Test Database Created ===\n";
    echo "Season: {$latestSeason->name} (Year: {$latestSeason->year})\n";
    echo "Regional Partners: " . RegionalPartner::count() . "\n";
    echo "Events: " . Event::count() . "\n";
    echo "Users: " . User::count() . "\n";
    echo "User-Regional Partner links: " . DB::table('user_regional_partner')->count() . "\n";
    
    echo "\nEvent configurations:\n";
    echo "  - Regional Partner A: Separate Explore + Challenge events\n";
    echo "  - Regional Partner B: Combined Explore + Challenge event\n";
    
    echo "\n✅ Fresh test database is ready!\n";
    echo "SSO Integration: Give your actual users the 'flow-tester' role in your IDP.\n";
    echo "Users will be automatically created and assigned to test regional partners on first login.\n";
}

// If running directly, execute the function
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    freshTestDatabase();
}