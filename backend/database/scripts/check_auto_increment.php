<?php

/**
 * Check AUTO_INCREMENT Status
 * 
 * Checks if all tables have AUTO_INCREMENT set correctly on their id column
 * as defined in the master migration.
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/check_auto_increment.php';
 * >>> checkAutoIncrement();
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function checkAutoIncrement()
{
    echo "🔍 Checking AUTO_INCREMENT Status in Dev Database\n";
    echo "================================================\n\n";

    // Get all tables from master migration that should have AUTO_INCREMENT
    $expectedAutoIncrement = [
        // m_ tables
        'm_season', 'm_level', 'm_news', 'm_room_type_group', 'm_room_type',
        'm_first_program', 'm_parameter', 'm_parameter_condition', 'm_activity_type',
        'm_activity_type_detail', 'm_insert_point', 'm_role', 'm_visibility', 'm_supported_plan',
        // Regular tables
        'regional_partner', 'event', 'slideshow', 'slide', 'publication',
        'user', 'news_user', 'user_regional_partner', 'room', 'room_type_room',
        'team', 'plan', 's_generator', 's_one_link_access', 'team_plan',
        'plan_param_value', 'match', 'extra_block', 'activity_group', 'activity',
        'logo', 'event_logo', 'table_event', 'q_plan', 'q_plan_team', 'q_run',
        'applications', 'api_keys', 'api_request_logs'
    ];

    $results = [];
    $dbName = DB::connection()->getDatabaseName();

    echo "Database: {$dbName}\n\n";

    foreach ($expectedAutoIncrement as $tableName) {
        if (!Schema::hasTable($tableName)) {
            $results[] = [
                'table' => $tableName,
                'status' => 'MISSING',
                'expected' => 'AUTO_INCREMENT',
                'actual' => 'TABLE NOT FOUND'
            ];
            continue;
        }
        
        // Check if id column exists and has AUTO_INCREMENT
        // Use backticks to handle reserved words like 'match'
        $columns = DB::select("SHOW COLUMNS FROM `{$tableName}` WHERE Field = 'id'");
        
        if (empty($columns)) {
            $results[] = [
                'table' => $tableName,
                'status' => 'NO_ID_COLUMN',
                'expected' => 'AUTO_INCREMENT on id',
                'actual' => 'No id column found'
            ];
            continue;
        }
        
        $column = $columns[0];
        $hasAutoIncrement = strpos($column->Extra, 'auto_increment') !== false;
        
        $results[] = [
            'table' => $tableName,
            'status' => $hasAutoIncrement ? 'OK' : 'MISSING',
            'expected' => 'AUTO_INCREMENT',
            'actual' => $hasAutoIncrement ? 'AUTO_INCREMENT' : 'NO AUTO_INCREMENT',
            'type' => $column->Type,
            'extra' => $column->Extra
        ];
    }

    // Display results
    $okCount = 0;
    $missingCount = 0;
    $missingTables = [];

    echo "Results:\n";
    echo str_repeat('=', 80) . "\n";
    printf("%-30s %-15s %-20s %s\n", 'Table', 'Status', 'Expected', 'Actual');
    echo str_repeat('-', 80) . "\n";

    foreach ($results as $result) {
        $status = $result['status'];
        if ($status === 'OK') {
            $okCount++;
            printf("%-30s %-15s %-20s %s\n", $result['table'], '✅ OK', $result['expected'], $result['actual']);
        } else {
            $missingCount++;
            $missingTables[] = $result['table'];
            printf("%-30s %-15s %-20s %s\n", $result['table'], '❌ ' . $status, $result['expected'], $result['actual']);
            if (isset($result['type'])) {
                echo str_repeat(' ', 30) . "   Type: " . $result['type'] . ", Extra: " . $result['extra'] . "\n";
            }
        }
    }

    echo str_repeat('=', 80) . "\n";
    echo "\nSummary:\n";
    echo "  ✅ Correct: {$okCount}\n";
    echo "  ❌ Issues: {$missingCount}\n";

    if ($missingCount > 0) {
        echo "\nTables with issues:\n";
        foreach ($missingTables as $table) {
            echo "  - {$table}\n";
        }
    }
    
    return $results;
}
