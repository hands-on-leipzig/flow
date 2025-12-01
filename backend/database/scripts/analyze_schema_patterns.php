<?php

/**
 * Analyze Schema Patterns
 * 
 * Analyzes the exported schema to identify patterns in data types,
 * column naming, foreign keys, etc.
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/analyze_schema_patterns.php';
 * >>> analyzeSchemaPatterns();
 */

use Illuminate\Support\Facades\DB;

function analyzeSchemaPatterns()
{
    echo "📊 Analyzing Schema Patterns...\n";
    echo "================================\n\n";
    
    $databaseName = DB::connection()->getDatabaseName();
    $tables = DB::select("SHOW TABLES");
    $tableKey = "Tables_in_{$databaseName}";
    
    $tableNames = [];
    foreach ($tables as $table) {
        $tableNames[] = $table->$tableKey;
    }
    
    // Analysis arrays
    $idTypes = [];
    $stringLengths = [];
    $dateTimeTypes = [];
    $integerTypes = [];
    $foreignKeyTypes = [];
    $nullablePatterns = [];
    $columnNamePatterns = [];
    
    foreach ($tableNames as $tableName) {
        $columns = DB::select("SHOW COLUMNS FROM `{$tableName}`");
        
        foreach ($columns as $column) {
            $colName = $column->Field;
            $colType = $column->Type;
            $colNull = $column->Null === 'YES';
            
            // Analyze ID columns
            if ($colName === 'id' || str_ends_with($colName, '_id')) {
                if (!isset($idTypes[$colType])) {
                    $idTypes[$colType] = [];
                }
                $idTypes[$colType][] = "{$tableName}.{$colName}";
            }
            
            // Analyze string types
            if (preg_match('/^(varchar|char)\((\d+)\)/', $colType, $matches)) {
                $length = (int)$matches[2];
                if (!isset($stringLengths[$length])) {
                    $stringLengths[$length] = [];
                }
                $stringLengths[$length][] = "{$tableName}.{$colName}";
            }
            
            // Analyze date/time types
            if (preg_match('/^(timestamp|datetime|date|time)/', $colType, $matches)) {
                $type = $matches[1];
                if (!isset($dateTimeTypes[$type])) {
                    $dateTimeTypes[$type] = [];
                }
                $dateTimeTypes[$type][] = "{$tableName}.{$colName}";
            }
            
            // Analyze integer types
            if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)/', $colType, $matches)) {
                $type = $matches[1];
                $unsigned = strpos($colType, 'unsigned') !== false;
                $key = $type . ($unsigned ? ' unsigned' : ' signed');
                if (!isset($integerTypes[$key])) {
                    $integerTypes[$key] = [];
                }
                $integerTypes[$key][] = "{$tableName}.{$colName}";
            }
            
            // Analyze nullable patterns
            if ($colNull) {
                if (!isset($nullablePatterns[$colType])) {
                    $nullablePatterns[$colType] = [];
                }
                $nullablePatterns[$colType][] = "{$tableName}.{$colName}";
            }
            
            // Analyze column name patterns
            if (str_ends_with($colName, '_id')) {
                $columnNamePatterns[] = [
                    'table' => $tableName,
                    'column' => $colName,
                    'type' => $colType,
                    'nullable' => $colNull
                ];
            }
        }
    }
    
    // Get foreign key information
    $foreignKeys = DB::select("
        SELECT 
            kcu.TABLE_NAME,
            kcu.COLUMN_NAME,
            kcu.REFERENCED_TABLE_NAME,
            kcu.REFERENCED_COLUMN_NAME,
            fk_col.COLUMN_TYPE as FK_COLUMN_TYPE,
            ref_col.COLUMN_TYPE as REF_COLUMN_TYPE,
            rc.DELETE_RULE
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
        INNER JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
            ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
            AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
        INNER JOIN INFORMATION_SCHEMA.COLUMNS fk_col
            ON kcu.TABLE_SCHEMA = fk_col.TABLE_SCHEMA
            AND kcu.TABLE_NAME = fk_col.TABLE_NAME
            AND kcu.COLUMN_NAME = fk_col.COLUMN_NAME
        INNER JOIN INFORMATION_SCHEMA.COLUMNS ref_col
            ON kcu.REFERENCED_TABLE_SCHEMA = ref_col.TABLE_SCHEMA
            AND kcu.REFERENCED_TABLE_NAME = ref_col.TABLE_NAME
            AND kcu.REFERENCED_COLUMN_NAME = ref_col.COLUMN_NAME
        WHERE kcu.TABLE_SCHEMA = ?
        AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
    ", [$databaseName]);
    
    $fkPatterns = [];
    foreach ($foreignKeys as $fk) {
        $key = $fk->FK_COLUMN_TYPE . ' -> ' . $fk->REFERENCED_TABLE_NAME . '.' . $fk->REFERENCED_COLUMN_NAME;
        if (!isset($fkPatterns[$key])) {
            $fkPatterns[$key] = [
                'count' => 0,
                'delete_rule' => $fk->DELETE_RULE,
                'examples' => []
            ];
        }
        $fkPatterns[$key]['count']++;
        if (count($fkPatterns[$key]['examples']) < 3) {
            $fkPatterns[$key]['examples'][] = "{$fk->TABLE_NAME}.{$fk->COLUMN_NAME}";
        }
    }
    
    // Output results
    echo "## 1. ID Column Types\n\n";
    ksort($idTypes);
    foreach ($idTypes as $type => $columns) {
        $count = count($columns);
        echo "**{$type}**: {$count} columns\n";
        if ($count <= 10) {
            echo "  - " . implode("\n  - ", $columns) . "\n";
        } else {
            echo "  - " . implode("\n  - ", array_slice($columns, 0, 10)) . "\n";
            echo "  - ... and " . ($count - 10) . " more\n";
        }
        echo "\n";
    }
    
    echo "## 2. String Length Patterns\n\n";
    ksort($stringLengths);
    foreach ($stringLengths as $length => $columns) {
        $count = count($columns);
        echo "**VARCHAR({$length})**: {$count} columns\n";
        if ($count <= 5) {
            echo "  - " . implode("\n  - ", $columns) . "\n";
        } else {
            echo "  - " . implode("\n  - ", array_slice($columns, 0, 5)) . "\n";
            echo "  - ... and " . ($count - 5) . " more\n";
        }
        echo "\n";
    }
    
    echo "## 3. Date/Time Types\n\n";
    foreach ($dateTimeTypes as $type => $columns) {
        $count = count($columns);
        echo "**{$type}**: {$count} columns\n";
        if ($count <= 10) {
            echo "  - " . implode("\n  - ", $columns) . "\n";
        } else {
            echo "  - " . implode("\n  - ", array_slice($columns, 0, 10)) . "\n";
            echo "  - ... and " . ($count - 10) . " more\n";
        }
        echo "\n";
    }
    
    echo "## 4. Integer Types\n\n";
    ksort($integerTypes);
    foreach ($integerTypes as $type => $columns) {
        $count = count($columns);
        echo "**{$type}**: {$count} columns\n";
        if ($count <= 10) {
            echo "  - " . implode("\n  - ", array_slice($columns, 0, 10)) . "\n";
        } else {
            echo "  - " . implode("\n  - ", array_slice($columns, 0, 5)) . "\n";
            echo "  - ... and " . ($count - 5) . " more\n";
        }
        echo "\n";
    }
    
    echo "## 5. Foreign Key Patterns\n\n";
    echo "### Foreign Key Types and Delete Rules\n\n";
    foreach ($fkPatterns as $pattern => $data) {
        echo "**{$pattern}**\n";
        echo "  - Count: {$data['count']}\n";
        echo "  - On Delete: {$data['delete_rule']}\n";
        echo "  - Examples: " . implode(", ", $data['examples']) . "\n\n";
    }
    
    echo "## 6. Foreign Key Type Matching\n\n";
    $fkMismatches = [];
    foreach ($foreignKeys as $fk) {
        if ($fk->FK_COLUMN_TYPE !== $fk->REF_COLUMN_TYPE) {
            $fkMismatches[] = [
                'table' => $fk->TABLE_NAME,
                'column' => $fk->COLUMN_NAME,
                'fk_type' => $fk->FK_COLUMN_TYPE,
                'ref_type' => $fk->REF_COLUMN_TYPE,
                'ref_table' => $fk->REFERENCED_TABLE_NAME,
                'ref_column' => $fk->REFERENCED_COLUMN_NAME
            ];
        }
    }
    
    if (empty($fkMismatches)) {
        echo "✅ All foreign keys match their referenced column types!\n\n";
    } else {
        echo "⚠️ Found " . count($fkMismatches) . " foreign keys with type mismatches:\n\n";
        foreach ($fkMismatches as $mismatch) {
            echo "- **{$mismatch['table']}.{$mismatch['column']}**\n";
            echo "  - FK Type: `{$mismatch['fk_type']}`\n";
            echo "  - References: `{$mismatch['ref_table']}.{$mismatch['ref_column']}` (Type: `{$mismatch['ref_type']}`)\n\n";
        }
    }
    
    echo "## 7. Nullable Patterns (Top Types)\n\n";
    arsort($nullablePatterns);
    $topNullable = array_slice($nullablePatterns, 0, 10, true);
    foreach ($topNullable as $type => $columns) {
        $count = count($columns);
        echo "**{$type}**: {$count} nullable columns\n";
        if ($count <= 5) {
            echo "  - " . implode("\n  - ", $columns) . "\n";
        } else {
            echo "  - " . implode("\n  - ", array_slice($columns, 0, 5)) . "\n";
            echo "  - ... and " . ($count - 5) . " more\n";
        }
        echo "\n";
    }
    
    echo "\n✅ Analysis complete!\n";
}

