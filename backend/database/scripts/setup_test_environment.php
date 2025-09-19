<?php

/**
 * Script to set up test environment with essential data
 * 
 * This script ensures the test environment has all necessary data
 * for the application to work properly.
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/setup_test_environment.php';
 * >>> setupTestEnvironment();
 */

use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\RegionalPartner;
use App\Models\Event;
use App\Models\MSeason;
use App\Models\MLevel;

function setupTestEnvironment()
{
    echo "Setting up test environment...\n";
    
    // 1. Ensure we have at least one season
    $season = MSeason::first();
    if (!$season) {
        $season = MSeason::create([
            'name' => 'Test Season 2024',
            'year' => 2024,
            'active' => true
        ]);
        echo "✓ Created season: {$season->name}\n";
    } else {
        echo "✓ Season exists: {$season->name}\n";
    }
    
    // 2. Ensure we have at least one level
    $level = MLevel::first();
    if (!$level) {
        $level = MLevel::create([
            'name' => 'Test Level',
            'level' => 1
        ]);
        echo "✓ Created level: {$level->name}\n";
    } else {
        echo "✓ Level exists: {$level->name}\n";
    }
    
    // 3. Create a test regional partner if none exists
    $testRP = RegionalPartner::where('name', 'Test Regional Partner')->first();
    if (!$testRP) {
        $testRP = RegionalPartner::create([
            'name' => 'Test Regional Partner',
            'region' => 'Test Region',
            'dolibarr_id' => 999999
        ]);
        echo "✓ Created test regional partner: {$testRP->name}\n";
    } else {
        echo "✓ Test regional partner exists: {$testRP->name}\n";
    }
    
    // 4. Create a test event if none exists for this RP
    $testEvent = Event::where('regional_partner', $testRP->id)->first();
    if (!$testEvent) {
        $testEvent = Event::create([
            'name' => 'Test Event 2024',
            'regional_partner' => $testRP->id,
            'season' => $season->id,
            'level' => $level->id,
            'date' => now()->addDays(30),
            'days' => 1,
            'slug' => 'test-event-2024'
        ]);
        echo "✓ Created test event: {$testEvent->name}\n";
    } else {
        echo "✓ Test event exists: {$testEvent->name}\n";
    }
    
    // 5. Create a test user if none exists
    $testUser = User::where('subject', 'test-user-123')->first();
    if (!$testUser) {
        $testUser = User::create([
            'subject' => 'test-user-123',
            'selection_event' => $testEvent->id,
            'selection_regional_partner' => $testRP->id
        ]);
        echo "✓ Created test user: {$testUser->id}\n";
    } else {
        echo "✓ Test user exists: {$testUser->id}\n";
    }
    
    // 6. Link user to regional partner
    $existingLink = DB::table('user_regional_partner')
        ->where('user', $testUser->id)
        ->where('regional_partner', $testRP->id)
        ->first();
    
    if (!$existingLink) {
        DB::table('user_regional_partner')->insert([
            'user' => $testUser->id,
            'regional_partner' => $testRP->id
        ]);
        echo "✓ Linked user to regional partner\n";
    } else {
        echo "✓ User already linked to regional partner\n";
    }
    
    // 7. Check main tables data
    $mainTables = [
        'm_room_type' => 'Room Types',
        'm_activity_type' => 'Activity Types',
        'm_parameter' => 'Parameters',
        'm_supported_plan' => 'Supported Plans',
        'm_level' => 'Levels',
        'm_season' => 'Seasons'
    ];
    
    echo "\nMain tables data:\n";
    foreach ($mainTables as $table => $name) {
        $count = DB::table($table)->count();
        echo "  {$name}: {$count} records\n";
    }
    
    // 8. Summary
    echo "\n=== Test Environment Setup Complete ===\n";
    echo "Test User ID: {$testUser->id}\n";
    echo "Test Regional Partner: {$testRP->name} (ID: {$testRP->id})\n";
    echo "Test Event: {$testEvent->name} (ID: {$testEvent->id})\n";
    echo "Season: {$season->name} (Year: {$season->year})\n";
    echo "Level: {$level->name} (Level: {$level->level})\n";
    
    echo "\nNext steps:\n";
    echo "1. Test the /events/selectable endpoint\n";
    echo "2. Check if the frontend can load data\n";
    echo "3. Verify user authentication works\n";
}

// If running directly, execute the function
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    setupTestEnvironment();
}
