<?php

/**
 * Generate Sync Migration
 * 
 * Generates a Laravel migration to sync the current database
 * to match the master migration schema.
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/generate_sync_migration.php';
 * >>> generateSyncMigration('dev');  // or 'test', 'prod'
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function generateSyncMigration($environment = null)
{
    $dbName = DB::connection()->getDatabaseName();
    $env = $environment ?: (str_contains($dbName, 'test') ? 'test' : (str_contains($dbName, 'prod') ? 'prod' : 'dev'));
    
    echo "🚀 Generating Sync Migration\n";
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
    
    // Step 1: Export current schema (if not exists)
    $schemaPath = dirname(base_path()) . "/{$env}_schema.md";
    if (!file_exists($schemaPath)) {
        echo "Step 1: Exporting current schema...\n";
        include base_path('database/scripts/export_schema.php');
        exportSchema($env);
        echo "✅ Schema exported\n\n";
    } else {
        echo "Step 1: Using existing schema export: {$schemaPath}\n\n";
    }
    
    // Step 2: Compare schemas (reuse comparison logic without re-including)
    echo "Step 2: Comparing schemas...\n";
    if (!function_exists('compareSchemaToMaster')) {
        include_once base_path('database/scripts/compare_schema_to_master.php');
    }
    $differences = compareSchemaToMaster($env);
    
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
    
    if ($totalIssues === 0) {
        echo "\n✅ No differences found. Database is already in sync!\n";
        return null;
    }
    
    // Step 3: Parse schemas for migration generation
    echo "\nStep 3: Parsing schemas for migration generation...\n";
    
    $currentSchema = parseDevSchemaSimple(file_get_contents($schemaPath));
    $masterPath = base_path('database/migrations/2025_01_01_000000_create_master_tables.php');
    $masterContent = file_get_contents($masterPath);
    
    $expectedTables = [
        'm_season', 'm_level', 'm_room_type_group', 'm_room_type',
        'm_first_program', 'm_parameter', 'm_parameter_condition', 'm_activity_type',
        'm_activity_type_detail', 'm_insert_point', 'm_role', 'm_visibility', 'm_supported_plan',
        'regional_partner', 'event', 'contao_public_rounds', 'slideshow', 'slide',
        'publication', 'user', 'news', 'news_user', 'user_regional_partner', 'room',
        'room_type_room', 'team', 'plan', 's_generator', 's_one_link_access',
        'team_plan', 'plan_param_value', 'match', 'extra_block', 'activity_group',
        'activity', 'logo', 'event_logo', 'table_event', 'q_plan', 'q_plan_team', 'q_run'
    ];
    
    $masterSchema = [];
    foreach ($expectedTables as $tableName) {
        $tableDef = extractTableDefinitionFromMaster($masterContent, $tableName);
        if ($tableDef && !empty($tableDef['columns'])) {
            $masterSchema[$tableName] = $tableDef;
        }
    }
    
    // Step 4: Generate migration
    echo "Step 4: Generating migration content...\n";
    $migrationContent = generateSyncMigrationContent($differences, $masterSchema, $currentSchema);
    
    // Step 5: Write migration file
    $migrationPath = base_path('database/migrations/' . date('Y_m_d_His') . '_sync_' . $env . '_to_master.php');
    file_put_contents($migrationPath, $migrationContent);
    
    echo "\n✅ Sync migration generated!\n";
    echo "📁 File: {$migrationPath}\n";
    echo "\n📋 Next steps:\n";
    echo "1. Review the migration file\n";
    echo "2. Test on a backup database if possible\n";
    echo "3. Run: php artisan migrate\n";
    echo "4. Verify with: compareSchemaToMaster('{$env}')\n";
    
    return $migrationPath;
}

