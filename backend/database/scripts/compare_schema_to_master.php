<?php

/**
 * Compare Current Schema to Master Migration
 * 
 * Compares the exported schema file against the master migration
 * and reports all differences.
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/compare_schema_to_master.php';
 * >>> compareSchemaToMaster('dev');  // or 'test', 'prod'
 */

use Illuminate\Support\Facades\DB;

function compareSchemaToMaster($environment = null)
{
    $dbName = DB::connection()->getDatabaseName();
    $env = $environment ?: (str_contains($dbName, 'test') ? 'test' : (str_contains($dbName, 'prod') ? 'prod' : 'dev'));
    
    echo "🔍 Comparing Schema to Master Migration\n";
    echo "Environment: {$env}\n";
    echo "=====================================\n\n";
    
    // Step 0: Check for extra tables first
    if (!function_exists('checkExtraTables')) {
        include_once base_path('database/scripts/check_extra_tables.php');
    }
    echo "Step 0: Checking for extra tables...\n";
    $extraTables = checkExtraTables($env);
    echo "\n";
    
    // Load helper functions
    if (!function_exists('parseDevSchemaSimple')) {
        include_once base_path('database/scripts/generate_sync_migration_simple.php');
    }
    
    // Read current schema
    $schemaPath = dirname(base_path()) . "/{$env}_schema.md";
    if (!file_exists($schemaPath)) {
        echo "❌ Schema file not found: {$schemaPath}\n";
        echo "   Run export_schema.php first!\n";
        return;
    }
    $currentSchema = parseDevSchemaSimple(file_get_contents($schemaPath));
    
    // Read master migration
    $masterPath = base_path('database/migrations/2025_01_01_000000_create_master_tables.php');
    if (!file_exists($masterPath)) {
        echo "❌ Master migration not found: {$masterPath}\n";
        return;
    }
    $masterContent = file_get_contents($masterPath);
    
    // Expected tables from master
    $expectedTables = [
        'm_season', 'm_level', 'm_news', 'm_room_type_group', 'm_room_type',
        'm_first_program', 'm_parameter', 'm_parameter_condition', 'm_activity_type',
        'm_activity_type_detail', 'm_insert_point', 'm_role', 'm_visibility', 'm_supported_plan',
        'regional_partner', 'event', 'contao_public_rounds', 'slideshow', 'slide',
        'publication', 'user', 'news_user', 'user_regional_partner', 'room',
        'room_type_room', 'team', 'plan', 's_generator', 's_one_link_access',
        'team_plan', 'plan_param_value', 'match', 'extra_block', 'activity_group',
        'activity', 'logo', 'event_logo', 'table_event', 'q_plan', 'q_plan_team', 'q_run'
    ];
    
    // Extract master schema
    $masterSchema = [];
    foreach ($expectedTables as $tableName) {
        $tableDef = extractTableDefinitionFromMaster($masterContent, $tableName);
        if ($tableDef && !empty($tableDef['columns'])) {
            $masterSchema[$tableName] = $tableDef;
        }
    }
    
    echo "✅ Master schema parsed: " . count($masterSchema) . " tables\n";
    echo "✅ Current schema parsed: " . count($currentSchema) . " tables\n\n";
    
    // Compare
    $differences = compareSchemasSimple($masterSchema, $currentSchema, $expectedTables);
    
    // Display results
    echo "📊 COMPARISON RESULTS\n";
    echo "====================\n\n";
    
    displayDifferencesSimple($differences);
    
    $totalIssues = count($differences['missing_tables']) +
                  count($differences['missing_columns']) +
                  count($differences['extra_columns']) +
                  count($differences['type_mismatches']) +
                  count($differences['nullable_mismatches']) +
                  count($differences['default_mismatches']) +
                  count($differences['missing_foreign_keys']) +
                  count($differences['wrong_foreign_keys']) +
                  count($differences['missing_indexes'] ?? []) +
                  count($differences['extra_indexes'] ?? []);
    
    echo "\n📋 Summary: {$totalIssues} total issues found\n";
    
    if ($totalIssues === 0) {
        echo "✅ Schema matches master migration perfectly!\n";
    } else {
        echo "⚠️  Run generate_sync_migration.php to create a migration to fix these issues.\n";
    }
    
    return $differences;
}

