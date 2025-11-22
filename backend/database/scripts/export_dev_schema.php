<?php

/**
 * Export Dev Database Schema for Review
 * 
 * This script exports the complete schema structure from the Dev database
 * to help compare with the master migration.
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/export_dev_schema.php';
 * >>> exportDevSchema();
 */

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

function exportDevSchema()
{
    echo "🔍 Exporting Dev Database Schema...\n";
    echo "=====================================\n\n";
    
    $output = [];
    $output[] = "# Dev Database Schema Export";
    $output[] = "Generated: " . date('Y-m-d H:i:s');
    $output[] = "Database: " . DB::connection()->getDatabaseName();
    $output[] = "";
    
    // Get all tables
    $tables = DB::select("SHOW TABLES");
    $databaseName = DB::connection()->getDatabaseName();
    $tableKey = "Tables_in_{$databaseName}";
    
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
            if ($keyName === 'PRIMARY') continue; // Skip primary key
            
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
        ", [$databaseName, $tableName]);
        
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
    
    // Write to file
    $outputPath = storage_path('app/dev_schema_export_' . date('Y-m-d_His') . '.md');
    file_put_contents($outputPath, implode("\n", $output));
    
    echo "\n✅ Schema exported successfully!\n";
    echo "📁 File: {$outputPath}\n";
    echo "\n";
    echo "Next steps:\n";
    echo "1. Review DATA_TYPE_STANDARDS.md and discuss data types\n";
    echo "2. Review exported schema table by table\n";
    echo "3. Compare with master migration\n";
    
    return $outputPath;
}

