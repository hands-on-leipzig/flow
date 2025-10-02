<?php

/**
 * Test script for user creation functionality
 * 
 * This script tests the user creation logic to ensure it works correctly
 * when a user with a new UID (sub) visits the application.
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/test_user_creation.php';
 * >>> testUserCreation();
 */

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

function testUserCreation()
{
    echo "🧪 Testing User Creation Functionality\n";
    echo "=====================================\n\n";
    
    try {
        // Test 1: Create a new user
        echo "Test 1: Creating a new user...\n";
        $testSubject = 'test-user-' . time();
        
        $user = User::firstOrCreate(
            ['subject' => $testSubject],
            [
                'subject' => $testSubject,
                'password' => null,
                'selection_event' => null,
                'selection_regional_partner' => null
            ]
        );
        
        if ($user->wasRecentlyCreated) {
            echo "  ✅ New user created successfully\n";
            echo "    - User ID: {$user->id}\n";
            echo "    - Subject: {$user->subject}\n";
        } else {
            echo "  ⚠ User already exists\n";
        }
        
        // Test 2: Try to create the same user again
        echo "\nTest 2: Attempting to create the same user again...\n";
        $sameUser = User::firstOrCreate(
            ['subject' => $testSubject],
            [
                'subject' => $testSubject,
                'password' => null,
                'selection_event' => null,
                'selection_regional_partner' => null
            ]
        );
        
        if ($sameUser->id === $user->id) {
            echo "  ✅ Same user retrieved (no duplicate created)\n";
        } else {
            echo "  ❌ Different user created (duplicate issue)\n";
        }
        
        // Test 3: Test with null subject
        echo "\nTest 3: Testing with null subject...\n";
        try {
            $nullUser = User::firstOrCreate(
                ['subject' => null],
                [
                    'subject' => null,
                    'password' => null,
                    'selection_event' => null,
                    'selection_regional_partner' => null
                ]
            );
            echo "  ✅ User with null subject handled\n";
        } catch (\Exception $e) {
            echo "  ⚠ User with null subject failed: " . $e->getMessage() . "\n";
        }
        
        // Test 4: Test with empty subject
        echo "\nTest 4: Testing with empty subject...\n";
        try {
            $emptyUser = User::firstOrCreate(
                ['subject' => ''],
                [
                    'subject' => '',
                    'password' => null,
                    'selection_event' => null,
                    'selection_regional_partner' => null
                ]
            );
            echo "  ✅ User with empty subject handled\n";
        } catch (\Exception $e) {
            echo "  ⚠ User with empty subject failed: " . $e->getMessage() . "\n";
        }
        
        // Test 5: Check database constraints
        echo "\nTest 5: Checking database constraints...\n";
        $userCount = User::count();
        echo "  - Total users in database: {$userCount}\n";
        
        $testUsers = User::where('subject', 'LIKE', 'test-user-%')->count();
        echo "  - Test users created: {$testUsers}\n";
        
        // Test 6: Test user fields
        echo "\nTest 6: Verifying user fields...\n";
        $userFields = ['id', 'subject', 'password', 'selection_event', 'selection_regional_partner'];
        foreach ($userFields as $field) {
            if (isset($user->$field)) {
                echo "  ✅ Field '{$field}': " . ($user->$field ?? 'null') . "\n";
            } else {
                echo "  ❌ Field '{$field}': missing\n";
            }
        }
        
        // Cleanup
        echo "\nCleanup: Removing test users...\n";
        $deletedCount = User::where('subject', 'LIKE', 'test-user-%')->delete();
        echo "  ✅ Deleted {$deletedCount} test users\n";
        
        echo "\n✅ User creation tests completed successfully!\n";
        
    } catch (\Exception $e) {
        echo "\n❌ Test failed: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
}

// If running directly, execute the function
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    testUserCreation();
}
