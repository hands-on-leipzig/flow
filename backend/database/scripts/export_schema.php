<?php

/**
 * Export Current Database Schema
 * 
 * Exports the complete schema structure from the currently connected database
 * to a Markdown file in the project root.
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/export_schema.php';
 * >>> exportSchema('dev');  // or 'test', 'prod'
 * 
 * The schema will be saved to: {environment}_schema.md in project root
 */

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

function exportSchema($environment = null)
{
    $dbName = DB::connection()->getDatabaseName();
    $env = $environment ?: (str_contains($dbName, 'test') ? 'test' : (str_contains($dbName, 'prod') ? 'prod' : 'dev'));
    
    echo "🔍 Exporting Database Schema...\n";
    echo "Database: {$dbName}\n";
    echo "Environment: {$env}\n";
    echo "=====================================\n\n";
    
    $output = [];
    $output[] = "# Database Schema Export";
    $output[] = "Generated: " . date('Y-m-d H:i:s');
    $output[] = "Database: {$dbName}";
    $output[] = "Environment: {$env}";
    $output[] = "";
    
    // Get all tables
    $tables = DB::select("SHOW TABLES");
    $tableKey = "Tables_in_{$dbName}";
    
    $tableNames = [];
    foreach ($tables as $table) {
        $tableNames[] = $table->$tableKey;
    }
    
    // Sort tables: m_* tables first, then others
    usort($tableNames, function($a, $b) {
        $aIsMaster = str_starts_with($a, 'm_');
        $bIsMaster = str_starts_with($b, 'm_');
        
        if ($aIsMaster && !$bIsMaster) return -1;
        if (!$aIsMaster && $bIsMaster) return 1;
        return strcmp($a, $b);
    });
    
    echo "Found " . count($tableNames) . " tables\n\n";
    
    foreach ($tableNames as $tableName) {
        echo "Processing table: {$tableName}...\n";
        
        $output[] = "## Table: `{$tableName}`";
        $output[] = "";
        
        // Get table structure
        $columns = DB::select("SHOW COLUMNS FROM `{$tableName}`");
        
        $output[] = "### Columns";
        $output[] = "";
        $output[] = "| Column | Type | Null | Key | Default | Extra |";
        $output[] = "|--------|------|------|-----|---------|-------|";
        
        foreach ($columns as $column) {
            $colName = $column->Field;
            $colType = $column->Type;
            $colNull = $column->Null === 'YES' ? 'YES' : 'NO';
            $colKey = $column->Key ?: '-';
            $colDefault = $column->Default !== null ? $column->Default : 'NULL';
            $colExtra = $column->Extra ?: '-';
            
            $output[] = "| `{$colName}` | `{$colType}` | {$colNull} | {$colKey} | {$colDefault} | {$colExtra} |";
        }
        
        $output[] = "";
        
        // Get indexes
        $indexes = DB::select("SHOW INDEXES FROM `{$tableName}`");
        $indexGroups = [];
        
        foreach ($indexes as $index) {
            $keyName = $index->Key_name;
            if ($keyName === 'PRIMARY') continue;
            
            if (!isset($indexGroups[$keyName])) {
                $indexGroups[$keyName] = [
                    'columns' => [],
                    'unique' => $index->Non_unique == 0,
                    'type' => $index->Index_type
                ];
            }
            $indexGroups[$keyName]['columns'][] = $index->Column_name;
        }
        
        if (!empty($indexGroups)) {
            $output[] = "### Indexes";
            $output[] = "";
            $output[] = "| Index Name | Columns | Unique | Type |";
            $output[] = "|------------|---------|--------|------|";
            
            foreach ($indexGroups as $keyName => $index) {
                $columns = implode(', ', $index['columns']);
                $unique = $index['unique'] ? 'YES' : 'NO';
                $type = $index['type'];
                $output[] = "| `{$keyName}` | {$columns} | {$unique} | {$type} |";
            }
            $output[] = "";
        }
        
        // Get foreign keys
        $foreignKeys = DB::select("
            SELECT 
                kcu.CONSTRAINT_NAME,
                kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                rc.UPDATE_RULE,
                rc.DELETE_RULE
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            INNER JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
            WHERE kcu.TABLE_SCHEMA = ?
            AND kcu.TABLE_NAME = ?
            AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
        ", [$dbName, $tableName]);
        
        if (!empty($foreignKeys)) {
            $output[] = "### Foreign Keys";
            $output[] = "";
            $output[] = "| Constraint | Column | References | On Update | On Delete |";
            $output[] = "|------------|--------|------------|-----------|----------|";
            
            foreach ($foreignKeys as $fk) {
                $constraint = $fk->CONSTRAINT_NAME;
                $column = $fk->COLUMN_NAME;
                $refTable = $fk->REFERENCED_TABLE_NAME;
                $refColumn = $fk->REFERENCED_COLUMN_NAME;
                $onUpdate = $fk->UPDATE_RULE;
                $onDelete = $fk->DELETE_RULE;
                
                $output[] = "| `{$constraint}` | `{$column}` | `{$refTable}`.`{$refColumn}` | {$onUpdate} | {$onDelete} |";
            }
            $output[] = "";
        }
        
        $output[] = "---";
        $output[] = "";
    }
    
    // Write to project root
    $outputPath = dirname(base_path()) . "/{$env}_schema.md";
    file_put_contents($outputPath, implode("\n", $output));
    
    echo "\n✅ Schema exported successfully!\n";
    echo "📁 File: {$outputPath}\n";
    
    return $outputPath;
}

