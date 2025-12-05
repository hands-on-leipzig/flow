<?php

/**
 * Check Foreign Key Relationships
 * 
 * Compares all foreign keys in the current database against the master migration
 * and reports all discrepancies.
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/check_foreign_keys.php';
 * >>> checkForeignKeys();
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Load helper functions first (they may define normalizeFKRule)
if (!function_exists('extractTableDefinitionFromMaster')) {
    include_once base_path('database/scripts/generate_sync_migration_simple.php');
}

function checkForeignKeys()
{
    echo "🔍 Checking Foreign Key Relationships Against Master Migration\n";
    echo "==============================================================\n\n";

    $dbName = DB::connection()->getDatabaseName();
    echo "Database: {$dbName}\n\n";

    // Read master migration
    $masterPath = base_path('database/migrations/2025_01_01_000000_create_master_tables.php');
    if (!file_exists($masterPath)) {
        echo "❌ Master migration not found: {$masterPath}\n";
        return;
    }
    
    $masterContent = file_get_contents($masterPath);
    
    // Extract all foreign keys from master migration
    $masterFKs = extractForeignKeysFromMaster($masterContent);
    
    echo "✅ Found " . count($masterFKs) . " foreign keys in master migration\n\n";
    
    // Get all foreign keys from current database
    $currentFKs = getAllForeignKeysFromDatabase($dbName);
    
    echo "✅ Found " . count($currentFKs) . " foreign keys in current database\n\n";
    
    // Compare
    $differences = compareForeignKeys($masterFKs, $currentFKs);
    
    // Display results
    displayForeignKeyDifferences($differences);
    
    return $differences;
}

function extractForeignKeysFromMaster($content)
{
    $fks = [];
    
    // Get all expected tables from master migration
    $expectedTables = [
        'm_season', 'm_level', 'm_news', 'm_room_type_group', 'm_room_type',
        'm_first_program', 'm_parameter', 'm_parameter_condition', 'm_activity_type',
        'm_activity_type_detail', 'm_insert_point', 'm_role', 'm_visibility', 'm_supported_plan',
        'regional_partner', 'event', 'contao_public_rounds', 'slideshow', 'slide',
        'publication', 'user', 'news_user', 'user_regional_partner', 'room',
        'room_type_room', 'team', 'plan', 's_generator', 's_one_link_access',
        'team_plan', 'plan_param_value', 'match', 'extra_block', 'activity_group',
        'activity', 'logo', 'event_logo', 'table_event', 'q_plan', 'q_plan_team', 'q_run',
        'applications', 'api_keys', 'api_request_logs'
    ];
    
    // Extract foreign keys for each table using existing extraction logic
    foreach ($expectedTables as $tableName) {
        $tableDef = extractTableDefinitionFromMaster($content, $tableName);
        
        if ($tableDef && isset($tableDef['foreign_keys']) && !empty($tableDef['foreign_keys'])) {
            foreach ($tableDef['foreign_keys'] as $fk) {
                $key = "{$tableName}.{$fk['column']}";
                $fks[$key] = [
                    'table' => $tableName,
                    'column' => $fk['column'],
                    'references_table' => $fk['references_table'],
                    'references_column' => $fk['references_column'],
                    'on_delete' => $fk['on_delete'],
                ];
            }
        }
    }
    
    return $fks;
}

function getAllForeignKeysFromDatabase($dbName)
{
    $fks = [];
    
    // Query information_schema for all foreign keys
    $results = DB::select("
        SELECT 
            kcu.TABLE_NAME,
            kcu.COLUMN_NAME,
            kcu.REFERENCED_TABLE_NAME,
            kcu.REFERENCED_COLUMN_NAME,
            rc.DELETE_RULE
        FROM information_schema.KEY_COLUMN_USAGE kcu
        INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
            ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
            AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
        WHERE kcu.TABLE_SCHEMA = ?
            AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY kcu.TABLE_NAME, kcu.COLUMN_NAME
    ", [$dbName]);
    
    foreach ($results as $row) {
        $key = "{$row->TABLE_NAME}.{$row->COLUMN_NAME}";
        $fks[$key] = [
            'table' => $row->TABLE_NAME,
            'column' => $row->COLUMN_NAME,
            'references_table' => $row->REFERENCED_TABLE_NAME,
            'references_column' => $row->REFERENCED_COLUMN_NAME,
            'on_delete' => normalizeFKRule($row->DELETE_RULE),
        ];
    }
    
    return $fks;
}

function compareForeignKeys($masterFKs, $currentFKs)
{
    $differences = [
        'missing' => [],
        'extra' => [],
        'wrong_references' => [],
        'wrong_delete_rule' => [],
    ];
    
    // Find missing FKs (in master but not in current)
    foreach ($masterFKs as $key => $masterFK) {
        if (!isset($currentFKs[$key])) {
            $differences['missing'][] = $masterFK;
        } else {
            $currentFK = $currentFKs[$key];
            
            // Check if references are correct
            if ($currentFK['references_table'] !== $masterFK['references_table'] ||
                $currentFK['references_column'] !== $masterFK['references_column']) {
                $differences['wrong_references'][] = [
                    'table' => $masterFK['table'],
                    'column' => $masterFK['column'],
                    'master' => $masterFK,
                    'current' => $currentFK,
                ];
            }
            
            // Check if delete rule is correct
            if ($currentFK['on_delete'] !== $masterFK['on_delete']) {
                $differences['wrong_delete_rule'][] = [
                    'table' => $masterFK['table'],
                    'column' => $masterFK['column'],
                    'master' => $masterFK['on_delete'],
                    'current' => $currentFK['on_delete'],
                    'fk' => $masterFK,
                ];
            }
        }
    }
    
    // Find extra FKs (in current but not in master)
    foreach ($currentFKs as $key => $currentFK) {
        if (!isset($masterFKs[$key])) {
            $differences['extra'][] = $currentFK;
        }
    }
    
    return $differences;
}

function displayForeignKeyDifferences($differences)
{
    echo "📊 COMPARISON RESULTS\n";
    echo "====================\n\n";
    
    $totalIssues = 0;
    
    // Missing FKs
    if (!empty($differences['missing'])) {
        $count = count($differences['missing']);
        $totalIssues += $count;
        echo "❌ Missing Foreign Keys: {$count}\n";
        foreach ($differences['missing'] as $fk) {
            echo "   - {$fk['table']}.{$fk['column']} -> {$fk['references_table']}.{$fk['references_column']} ({$fk['on_delete']})\n";
        }
        echo "\n";
    }
    
    // Wrong references
    if (!empty($differences['wrong_references'])) {
        $count = count($differences['wrong_references']);
        $totalIssues += $count;
        echo "❌ Wrong References: {$count}\n";
        foreach ($differences['wrong_references'] as $diff) {
            echo "   - {$diff['table']}.{$diff['column']}\n";
            echo "     Master: -> {$diff['master']['references_table']}.{$diff['master']['references_column']}\n";
            echo "     Current: -> {$diff['current']['references_table']}.{$diff['current']['references_column']}\n";
        }
        echo "\n";
    }
    
    // Wrong delete rules
    if (!empty($differences['wrong_delete_rule'])) {
        $count = count($differences['wrong_delete_rule']);
        $totalIssues += $count;
        echo "❌ Wrong Delete Rules: {$count}\n";
        foreach ($differences['wrong_delete_rule'] as $diff) {
            echo "   - {$diff['table']}.{$diff['column']} -> {$diff['fk']['references_table']}.{$diff['fk']['references_column']}\n";
            echo "     Master: {$diff['master']}, Current: {$diff['current']}\n";
        }
        echo "\n";
    }
    
    // Extra FKs
    if (!empty($differences['extra'])) {
        $count = count($differences['extra']);
        $totalIssues += $count;
        echo "⚠️  Extra Foreign Keys (not in master): {$count}\n";
        foreach (array_slice($differences['extra'], 0, 10) as $fk) {
            echo "   - {$fk['table']}.{$fk['column']} -> {$fk['references_table']}.{$fk['references_column']} ({$fk['on_delete']})\n";
        }
        if ($count > 10) {
            echo "   ... and " . ($count - 10) . " more\n";
        }
        echo "\n";
    }
    
    echo "📋 Summary: {$totalIssues} total issues found\n";
    
    if ($totalIssues === 0) {
        echo "✅ All foreign keys match master migration perfectly!\n";
    }
    
    return $differences;
}
