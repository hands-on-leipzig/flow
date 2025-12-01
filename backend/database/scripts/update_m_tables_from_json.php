<?php

/**
 * Update m-tables from JSON without dropping tables or disabling FK checks
 * 
 * This script implements incremental updates:
 * - UPDATE existing records (by ID)
 * - INSERT new records (preserving IDs from JSON)
 * - DELETE removed records (handling FK constraints properly)
 * 
 * FK constraints are kept ENABLED throughout the process to maintain data integrity.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Discover dependency order dynamically from FK relationships
 */
function discoverDependencyOrder(array $tables): array {
    echo "🔍 Discovering dependency order from FK relationships...\n";
    
    $databaseName = DB::connection()->getDatabaseName();
    $dependencies = [];
    $tableSet = array_flip($tables);
    
    // Get all FK relationships for m-tables
    $foreignKeys = DB::select("
        SELECT 
            TABLE_NAME,
            REFERENCED_TABLE_NAME,
            CONSTRAINT_NAME,
            DELETE_RULE
        FROM information_schema.KEY_COLUMN_USAGE kcu
        JOIN information_schema.REFERENTIAL_CONSTRAINTS rc 
            ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
            AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
        WHERE kcu.CONSTRAINT_SCHEMA = ?
        AND kcu.TABLE_NAME IN (" . implode(',', array_map(fn($t) => "'$t'", $tables)) . ")
        AND kcu.REFERENCED_TABLE_NAME IN (" . implode(',', array_map(fn($t) => "'$t'", $tables)) . ")
    ", [$databaseName]);
    
    // Build dependency graph
    foreach ($foreignKeys as $fk) {
        if (isset($tableSet[$fk->TABLE_NAME]) && isset($tableSet[$fk->REFERENCED_TABLE_NAME])) {
            if (!isset($dependencies[$fk->TABLE_NAME])) {
                $dependencies[$fk->TABLE_NAME] = [];
            }
            $dependencies[$fk->TABLE_NAME][] = $fk->REFERENCED_TABLE_NAME;
        }
    }
    
    // Topological sort
    $sorted = [];
    $visited = [];
    $visiting = [];
    
    function visit($table, $dependencies, &$sorted, &$visited, &$visiting) {
        if (isset($visiting[$table])) {
            throw new \Exception("Circular dependency detected involving table: $table");
        }
        if (isset($visited[$table])) {
            return;
        }
        
        $visiting[$table] = true;
        
        if (isset($dependencies[$table])) {
            foreach ($dependencies[$table] as $dep) {
                visit($dep, $dependencies, $sorted, $visited, $visiting);
            }
        }
        
        unset($visiting[$table]);
        $visited[$table] = true;
        $sorted[] = $table;
    }
    
    foreach ($tables as $table) {
        if (!isset($visited[$table])) {
            visit($table, $dependencies, $sorted, $visited, $visiting);
        }
    }
    
    echo "✓ Dependency order: " . implode(' → ', $sorted) . "\n";
    return $sorted;
}

/**
 * Validate schema matches JSON structure
 */
function validateSchemaMatchesJson(array $jsonData): void {
    echo "🔍 Validating schema matches JSON structure...\n";
    
    $tables = $jsonData['_metadata']['tables'] ?? [];
    $errors = [];
    
    foreach ($tables as $table) {
        if (!Schema::hasTable($table)) {
            $errors[] = "Table {$table} does not exist. Please run migrations first.";
            continue;
        }
        
        $jsonRecords = $jsonData[$table] ?? [];
        if (empty($jsonRecords)) {
            continue; // Skip empty tables
        }
        
        // Get columns from first JSON record
        $jsonColumns = array_keys($jsonRecords[0]);
        
        // Get actual table columns
        $dbColumns = Schema::getColumnListing($table);
        
        // Check for missing columns
        $missingColumns = array_diff($jsonColumns, $dbColumns);
        if (!empty($missingColumns)) {
            $errors[] = "Table {$table} is missing columns: " . implode(', ', $missingColumns) . ". Please run migrations first.";
        }
        
        // Check for extra columns (warn but don't fail)
        $extraColumns = array_diff($dbColumns, $jsonColumns);
        if (!empty($extraColumns)) {
            echo "  ⚠️  Table {$table} has extra columns (not in JSON): " . implode(', ', $extraColumns) . "\n";
        }
    }
    
    if (!empty($errors)) {
        throw new \Exception("Schema validation failed:\n" . implode("\n", $errors));
    }
    
    echo "✓ Schema validation passed\n";
}

/**
 * Ensure AUTO_INCREMENT is high enough for new IDs
 */
function ensureAutoIncrement(string $table, array $jsonRecords): void {
    if (empty($jsonRecords)) {
        return;
    }
    
    $jsonIds = array_filter(array_column($jsonRecords, 'id'), fn($id) => $id !== null);
    if (empty($jsonIds)) {
        return; // No IDs in JSON
    }
    
    $maxJsonId = max($jsonIds);
    
    // Get current AUTO_INCREMENT value
    $tableStatus = DB::select("SHOW TABLE STATUS LIKE ?", [$table]);
    if (empty($tableStatus)) {
        return;
    }
    
    $currentAutoIncrement = $tableStatus[0]->Auto_increment ?? 1;
    
    if ($maxJsonId >= $currentAutoIncrement) {
        $newAutoIncrement = $maxJsonId + 1;
        DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = {$newAutoIncrement}");
        echo "  ✓ Adjusted AUTO_INCREMENT for {$table} to {$newAutoIncrement}\n";
    }
}

/**
 * Check if a record can be deleted (no RESTRICT constraints blocking it)
 */
function canDeleteSafely(string $table, int $id): array {
    $databaseName = DB::connection()->getDatabaseName();
    
    // Find all FK constraints that reference this table with RESTRICT
    $restrictReferences = DB::select("
        SELECT 
            kcu.TABLE_NAME,
            kcu.COLUMN_NAME,
            rc.DELETE_RULE,
            COUNT(*) as reference_count
        FROM information_schema.KEY_COLUMN_USAGE kcu
        JOIN information_schema.REFERENTIAL_CONSTRAINTS rc 
            ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
            AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
        WHERE kcu.CONSTRAINT_SCHEMA = ?
        AND kcu.REFERENCED_TABLE_NAME = ?
        AND rc.DELETE_RULE = 'RESTRICT'
        GROUP BY kcu.TABLE_NAME, kcu.COLUMN_NAME, rc.DELETE_RULE
    ", [$databaseName, $table]);
    
    $blockingReferences = [];
    
    foreach ($restrictReferences as $ref) {
        // Check if any records actually reference this ID
        $count = DB::table($ref->TABLE_NAME)
            ->where($ref->COLUMN_NAME, $id)
            ->count();
        
        if ($count > 0) {
            $blockingReferences[] = [
                'table' => $ref->TABLE_NAME,
                'column' => $ref->COLUMN_NAME,
                'count' => $count
            ];
        }
    }
    
    return [
        'can_delete' => empty($blockingReferences),
        'blocking_references' => $blockingReferences
    ];
}

/**
 * Update a single m-table from JSON
 */
function updateMTable(string $table, array $jsonRecords): array {
    $displayName = str_replace('m_', '', $table);
    echo "  📝 Updating {$displayName}...\n";
    
    $report = [
        'table' => $table,
        'updated' => 0,
        'inserted' => 0,
        'deleted' => 0,
        'skipped' => [],
        'errors' => []
    ];
    
    if (empty($jsonRecords)) {
        echo "    ⚠️  No data in JSON for {$table}\n";
        return $report;
    }
    
    // Ensure AUTO_INCREMENT is high enough
    ensureAutoIncrement($table, $jsonRecords);
    
    // Get current records from DB
    $dbRecords = DB::table($table)->get()->keyBy('id');
    
    // Get table columns to filter JSON data
    $tableColumns = Schema::getColumnListing($table);
    
    // Process updates and inserts
    foreach ($jsonRecords as $jsonRecord) {
        try {
            $id = $jsonRecord['id'] ?? null;
            
            if ($id === null) {
                $report['errors'][] = "Record missing 'id' field";
                continue;
            }
            
            // Filter to only include columns that exist in table
            $filteredRecord = array_intersect_key($jsonRecord, array_flip($tableColumns));
            
            if (isset($dbRecords[$id])) {
                // Update existing record
                DB::table($table)
                    ->where('id', $id)
                    ->update($filteredRecord);
                $report['updated']++;
            } else {
                // Insert new record (with explicit ID)
                DB::table($table)->insert($filteredRecord);
                $report['inserted']++;
            }
        } catch (\Exception $e) {
            $report['errors'][] = "Error processing record id={$id}: " . $e->getMessage();
        }
    }
    
    // Process deletes (records in DB but not in JSON)
    $jsonIds = array_filter(array_column($jsonRecords, 'id'), fn($id) => $id !== null);
    $jsonIdsSet = array_flip($jsonIds);
    
    foreach ($dbRecords as $id => $dbRecord) {
        if (!isset($jsonIdsSet[$id])) {
            // Record exists in DB but not in JSON - try to delete
            try {
                $canDelete = canDeleteSafely($table, $id);
                
                if ($canDelete['can_delete']) {
                    // Safe to delete (CASCADE will handle operational data)
                    DB::table($table)->where('id', $id)->delete();
                    $report['deleted']++;
                } else {
                    // Blocked by RESTRICT constraint - fail deployment
                    $blocking = $canDelete['blocking_references'];
                    $blockingDetails = array_map(
                        fn($b) => "{$b['table']}.{$b['column']} ({$b['count']} records)",
                        $blocking
                    );
                    
                    throw new \Exception(
                        "Cannot delete {$table}.id={$id} - referenced by operational data with RESTRICT constraint:\n" .
                        "  " . implode("\n  ", $blockingDetails) . "\n" .
                        "Deployment blocked. Please update operational data first."
                    );
                }
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'Cannot delete')) {
                    throw $e; // Re-throw our custom error
                }
                $report['errors'][] = "Error deleting record id={$id}: " . $e->getMessage();
            }
        }
    }
    
    echo "    ✓ Updated: {$report['updated']}, Inserted: {$report['inserted']}, Deleted: {$report['deleted']}\n";
    
    return $report;
}

/**
 * Main function: Update all m-tables from JSON
 */
function updateMTablesFromJson(string $jsonPath): array {
    echo "🔄 Updating m-tables from JSON (FK checks ENABLED)...\n";
    echo "================================================\n\n";
    
    // Load JSON
    if (!file_exists($jsonPath)) {
        throw new \Exception("JSON file not found: {$jsonPath}");
    }
    
    $content = file_get_contents($jsonPath);
    $jsonData = json_decode($content, true);
    
    if (!$jsonData || !isset($jsonData['_metadata'])) {
        throw new \Exception('Invalid JSON file format - missing _metadata');
    }
    
    $tables = $jsonData['_metadata']['tables'] ?? [];
    if (empty($tables)) {
        throw new \Exception('No tables found in JSON metadata');
    }
    
    echo "Found " . count($tables) . " tables in JSON:\n";
    foreach ($tables as $table) {
        $count = count($jsonData[$table] ?? []);
        echo "  - {$table}: {$count} records\n";
    }
    echo "\n";
    
    // Validate schema
    validateSchemaMatchesJson($jsonData);
    
    // Discover dependency order
    $orderedTables = discoverDependencyOrder($tables);
    
    // Update tables in dependency order
    $overallReport = [];
    
    DB::transaction(function() use ($orderedTables, $jsonData, &$overallReport) {
        foreach ($orderedTables as $table) {
            $jsonRecords = $jsonData[$table] ?? [];
            $report = updateMTable($table, $jsonRecords);
            $overallReport[$table] = $report;
        }
    });
    
    // Summary
    echo "\n📊 Update Summary:\n";
    $totalUpdated = 0;
    $totalInserted = 0;
    $totalDeleted = 0;
    $totalErrors = 0;
    
    foreach ($overallReport as $table => $report) {
        $totalUpdated += $report['updated'];
        $totalInserted += $report['inserted'];
        $totalDeleted += $report['deleted'];
        $totalErrors += count($report['errors']);
    }
    
    echo "  ✓ Updated: {$totalUpdated} records\n";
    echo "  ✓ Inserted: {$totalInserted} records\n";
    echo "  ✓ Deleted: {$totalDeleted} records\n";
    
    if ($totalErrors > 0) {
        echo "  ❌ Errors: {$totalErrors}\n";
        foreach ($overallReport as $table => $report) {
            if (!empty($report['errors'])) {
                echo "    {$table}:\n";
                foreach ($report['errors'] as $error) {
                    echo "      - {$error}\n";
                }
            }
        }
        throw new \Exception("Update completed with {$totalErrors} error(s)");
    }
    
    echo "\n✅ All m-tables updated successfully!\n";
    
    return $overallReport;
}

