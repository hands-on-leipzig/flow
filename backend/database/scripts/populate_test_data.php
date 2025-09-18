<?php

/**
 * Script to populate test environment with real data
 * 
 * This script copies some real data from the existing database
 * to make the test environment more realistic.
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/populate_test_data.php';
 * >>> populateTestData();
 */

use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\RegionalPartner;
use App\Models\Event;
use App\Models\MSeason;
use App\Models\MLevel;

function populateTestData()
{
    echo "Populating test environment with real data...\n";
    
    // 1. Get the latest season
    $latestSeason = MSeason::latest('year')->first();
    if (!$latestSeason) {
        echo "❌ No season found. Please run setup_test_environment.php first.\n";
        return;
    }
    echo "✓ Using season: {$latestSeason->name} (Year: {$latestSeason->year})\n";
    
    // 2. Get a few real regional partners
    $realRPs = RegionalPartner::where('id', '!=', 99) // Exclude our test RP
        ->where('name', 'NOT LIKE', '%Test%')
        ->limit(5)
        ->get();
    
    if ($realRPs->count() == 0) {
        echo "❌ No real regional partners found.\n";
        return;
    }
    
    echo "✓ Found {$realRPs->count()} real regional partners\n";
    
    // 3. Create events for these regional partners
    $eventsCreated = 0;
    foreach ($realRPs as $rp) {
        // Check if this RP already has events in the current season
        $existingEvents = Event::where('regional_partner', $rp->id)
            ->where('season', $latestSeason->id)
            ->count();
        
        if ($existingEvents == 0) {
            // Create a test event for this RP
            $event = Event::create([
                'name' => "Test Event - {$rp->name}",
                'regional_partner' => $rp->id,
                'season' => $latestSeason->id,
                'level' => 1, // Use level 1 for all test events
                'date' => now()->addDays(rand(1, 90)), // Random date in next 3 months
                'days' => 1,
                'slug' => 'test-event-' . strtolower(str_replace(' ', '-', $rp->name))
            ]);
            $eventsCreated++;
            echo "  ✓ Created event: {$event->name}\n";
        } else {
            echo "  ✓ RP {$rp->name} already has events in current season\n";
        }
    }
    
    echo "✓ Created {$eventsCreated} new events\n";
    
    // 4. Create test users for some of these regional partners
    $testUsersCreated = 0;
    foreach ($realRPs->take(3) as $rp) {
        $testUserId = "test-user-{$rp->id}";
        $existingUser = User::where('subject', $testUserId)->first();
        
        if (!$existingUser) {
            $user = User::create([
                'subject' => $testUserId,
                'selection_event' => null, // Will be set when user selects an event
                'selection_regional_partner' => $rp->id
            ]);
            
            // Link user to regional partner
            DB::table('user_regional_partner')->insert([
                'user' => $user->id,
                'regional_partner' => $rp->id
            ]);
            
            $testUsersCreated++;
            echo "  ✓ Created test user: {$testUserId} (ID: {$user->id})\n";
        } else {
            echo "  ✓ Test user {$testUserId} already exists\n";
        }
    }
    
    echo "✓ Created {$testUsersCreated} new test users\n";
    
    // 5. Summary
    echo "\n=== Test Data Population Complete ===\n";
    echo "Season: {$latestSeason->name} (Year: {$latestSeason->year})\n";
    echo "Regional Partners: " . RegionalPartner::count() . "\n";
    echo "Events in current season: " . Event::where('season', $latestSeason->id)->count() . "\n";
    echo "Users: " . User::count() . "\n";
    echo "User-Regional Partner links: " . DB::table('user_regional_partner')->count() . "\n";
    
    echo "\nTest users created:\n";
    $testUsers = User::where('subject', 'LIKE', 'test-user-%')->get();
    foreach ($testUsers as $user) {
        $rp = $user->regionalPartners()->first();
        echo "  - {$user->subject} (ID: {$user->id}) -> {$rp->name}\n";
    }
    
    echo "\nNext steps:\n";
    echo "1. Test the /events/selectable endpoint\n";
    echo "2. Check if the frontend can load data\n";
    echo "3. Verify user authentication works\n";
    echo "4. Test with different user roles\n";
}

// If running directly, execute the function
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    populateTestData();
}
