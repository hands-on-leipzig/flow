<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix missing foreign keys.
     * 
     * This migration automatically discovers all foreign keys that are defined
     * in the master migration but missing in the current database, and adds them.
     * Works across all environments (dev, test, prod) regardless of which tables exist.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }

        // Load helper functions
        if (!function_exists('extractTableDefinitionFromMaster')) {
            include_once base_path('database/scripts/generate_sync_migration_simple.php');
        }
        
        if (!function_exists('normalizeFKRule')) {
            include_once base_path('database/scripts/generate_sync_migration_simple.php');
        }

        $dbName = DB::connection()->getDatabaseName();
        
        // Read master migration
        $masterPath = base_path('database/migrations/2025_01_01_000000_create_master_tables.php');
        if (!file_exists($masterPath)) {
            \Log::error("Master migration not found: {$masterPath}");
            return;
        }
        
        $masterContent = file_get_contents($masterPath);
        
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
        
        // Extract all foreign keys from master migration
        $masterFKs = [];
        foreach ($expectedTables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue; // Skip if table doesn't exist
            }
            
            $tableDef = extractTableDefinitionFromMaster($masterContent, $tableName);
            
            if ($tableDef && isset($tableDef['foreign_keys']) && !empty($tableDef['foreign_keys'])) {
                foreach ($tableDef['foreign_keys'] as $fk) {
                    $key = "{$tableName}.{$fk['column']}";
                    $masterFKs[$key] = [
                        'table' => $tableName,
                        'column' => $fk['column'],
                        'references_table' => $fk['references_table'],
                        'references_column' => $fk['references_column'],
                        'on_delete' => $fk['on_delete'],
                    ];
                }
            }
        }
        
        // Get all existing foreign keys from database
        $existingFKs = [];
        $results = DB::select("
            SELECT 
                kcu.TABLE_NAME,
                kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE kcu
            INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = ?
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
        ", [$dbName]);
        
        foreach ($results as $row) {
            $key = "{$row->TABLE_NAME}.{$row->COLUMN_NAME}";
            $existingFKs[$key] = true;
        }
        
        // Find missing foreign keys and add them
        foreach ($masterFKs as $key => $fk) {
            if (!isset($existingFKs[$key])) {
                $tableName = $fk['table'];
                $column = $fk['column'];
                $refTable = $fk['references_table'];
                $refColumn = $fk['references_column'];
                $onDelete = strtolower($fk['on_delete']);
                
                // Check if referenced table exists
                if (!Schema::hasTable($refTable)) {
                    \Log::warning("Skipping FK {$tableName}.{$column} -> {$refTable}.{$refColumn}: Referenced table does not exist");
                    continue;
                }
                
                // Check if column exists in source table
                if (!Schema::hasColumn($tableName, $column)) {
                    \Log::warning("Skipping FK {$tableName}.{$column} -> {$refTable}.{$refColumn}: Column does not exist");
                    continue;
                }
                
                // Check for orphaned records before creating FK
                try {
                    $orphaned = DB::selectOne("
                        SELECT COUNT(*) as cnt 
                        FROM `{$tableName}` t
                        LEFT JOIN `{$refTable}` r ON t.`{$column}` = r.`{$refColumn}`
                        WHERE t.`{$column}` IS NOT NULL AND r.`{$refColumn}` IS NULL
                    ");
                    
                    if ($orphaned && $orphaned->cnt > 0) {
                        \Log::warning("Skipping FK {$tableName}.{$column} -> {$refTable}.{$refColumn}: Found {$orphaned->cnt} orphaned records");
                        continue;
                    }
                } catch (\Throwable $e) {
                    \Log::warning("Could not check for orphaned records for {$tableName}.{$column}: " . $e->getMessage());
                    // Continue anyway - try to add the FK
                }
                
                // Check if FK already exists (by column, table, and references)
                $existingFK = DB::selectOne("
                    SELECT kcu.CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE kcu
                    INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                        ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                        AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                    WHERE kcu.TABLE_SCHEMA = ?
                        AND kcu.TABLE_NAME = ?
                        AND kcu.COLUMN_NAME = ?
                        AND kcu.REFERENCED_TABLE_NAME = ?
                        AND kcu.REFERENCED_COLUMN_NAME = ?
                ", [$dbName, $tableName, $column, $refTable, $refColumn]);
                
                if ($existingFK) {
                    continue; // FK already exists, skip
                }
                
                // Step 1: Ensure column has an index (required before adding FK)
                // Foreign keys require an index on the referencing column
                $hasIndex = false;
                $indexes = DB::select("SHOW INDEXES FROM `{$tableName}` WHERE Column_name = ?", [$column]);
                foreach ($indexes as $idx) {
                    if ($idx->Key_name !== 'PRIMARY') {
                        $hasIndex = true;
                        break;
                    }
                }
                
                // Add index first if missing (required before adding FK)
                if (!$hasIndex) {
                    try {
                        Schema::table($tableName, function (Blueprint $table) use ($column) {
                            $table->index($column);
                        });
                    } catch (\Throwable $e) {
                        \Log::warning("Failed to add index on {$tableName}.{$column}: " . $e->getMessage());
                        continue; // Can't add FK without index
                    }
                }
                
                // Step 2: Add the foreign key
                try {
                    Schema::table($tableName, function (Blueprint $table) use ($column, $refColumn, $refTable, $onDelete) {
                        if ($onDelete === 'set null') {
                            $table->foreign($column)->references($refColumn)->on($refTable)->nullOnDelete();
                        } else {
                            $table->foreign($column)->references($refColumn)->on($refTable)->onDelete($onDelete);
                        }
                    });
                } catch (\Throwable $e) {
                    \Log::warning("Failed to add FK {$tableName}.{$column} -> {$refTable}.{$refColumn}: " . $e->getMessage());
                }
            }
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rollback not implemented - removing foreign keys is risky
        // If needed, use check_foreign_keys.php to identify what to remove
    }
};
