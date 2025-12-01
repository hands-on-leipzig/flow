<?php

/**
 * Check for Orphaned Records
 * 
 * Finds orphaned records that are blocking foreign key creation.
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/check_orphans.php';
 * >>> checkOrphans('prod');
 */

use Illuminate\Support\Facades\DB;

function checkOrphans($environment = null)
{
    $dbName = DB::connection()->getDatabaseName();
    $env = $environment ?: (str_contains($dbName, 'test') ? 'test' : (str_contains($dbName, 'prod') ? 'prod' : 'dev'));
    
    echo "🔍 Checking for Orphaned Records Blocking FK Creation\n";
    echo "Environment: {$env}\n";
    echo "=====================================\n\n";
    
    // Get current FKs
    $currentFKs = DB::select("
        SELECT 
            kcu.TABLE_NAME,
            kcu.COLUMN_NAME,
            kcu.REFERENCED_TABLE_NAME,
            kcu.REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE kcu
        WHERE kcu.TABLE_SCHEMA = DATABASE()
        AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
    ");
    
    $currentFKMap = [];
    foreach ($currentFKs as $fk) {
        $key = $fk->TABLE_NAME . '.' . $fk->COLUMN_NAME;
        $currentFKMap[$key] = true;
    }
    
    // Get expected FKs from master migration by parsing it
    $masterPath = base_path('database/migrations/2025_01_01_000000_create_master_tables.php');
    $masterContent = file_get_contents($masterPath);
    
    // Parse all FKs from master migration
    $expectedFKs = [];
    preg_match_all("/Schema::(?:create|table)\s*\(['\"](\w+)['\"]/i", $masterContent, $tableMatches, PREG_OFFSET_CAPTURE);
    
    for ($i = 0; $i < count($tableMatches[1]); $i++) {
        $tableName = $tableMatches[1][$i][0];
        $startPos = $tableMatches[0][$i][1];
        $endPos = isset($tableMatches[0][$i + 1]) ? $tableMatches[0][$i + 1][1] : strlen($masterContent);
        
        $tableDef = substr($masterContent, $startPos, $endPos - $startPos);
        
        // Extract FKs from this table
        preg_match_all(
            "/\\\$table->foreign\s*\(\s*['\"]([^'\"]+)['\"]\s*\)\s*->references\s*\(\s*['\"]([^'\"]+)['\"]\s*\)\s*->on\s*\(\s*['\"]([^'\"]+)['\"]\s*\)\s*(?:->(?:onDelete\s*\(\s*['\"]([^'\"]+)['\"]\s*\)|nullOnDelete\s*\(\s*\)))?/s",
            $tableDef,
            $fkMatches,
            PREG_SET_ORDER
        );
        
        foreach ($fkMatches as $fk) {
            // Verify column exists in table
            if (Schema::hasTable($tableName)) {
                if (Schema::hasColumn($tableName, $fk[1])) {
                    $expectedFKs[] = [
                        'table' => $tableName,
                        'column' => $fk[1],
                        'ref_table' => $fk[3],
                        'ref_col' => $fk[2],
                    ];
                }
            }
        }
    }
    
    $orphanedFKs = [];
    $totalOrphans = 0;
    
    foreach ($expectedFKs as $check) {
        $key = $check['table'] . '.' . $check['column'];
        if (!isset($currentFKMap[$key])) {
            // Check for orphaned records
            $table = $check['table'];
            $column = $check['column'];
            $refTable = $check['ref_table'];
            $refCol = $check['ref_col'];
            
            $orphaned = DB::selectOne(
                "SELECT COUNT(*) as cnt FROM `{$table}` t
                 LEFT JOIN `{$refTable}` r ON t.`{$column}` = r.`{$refCol}`
                 WHERE t.`{$column}` IS NOT NULL AND r.`{$refCol}` IS NULL"
            );
            
            if ($orphaned && $orphaned->cnt > 0) {
                $orphanedFKs[] = [
                    'table' => $table,
                    'column' => $column,
                    'ref_table' => $refTable,
                    'ref_col' => $refCol,
                    'count' => $orphaned->cnt
                ];
                $totalOrphans += $orphaned->cnt;
            }
        }
    }
    
    if (count($orphanedFKs) > 0) {
        echo "⚠️  Found " . count($orphanedFKs) . " FKs blocked by orphaned records:\n\n";
        foreach ($orphanedFKs as $fk) {
            echo "  {$fk['table']}.{$fk['column']} -> {$fk['ref_table']}.{$fk['ref_col']}: {$fk['count']} orphans\n";
        }
        echo "\nTotal orphaned records: {$totalOrphans}\n";
    } else {
        echo "✅ No orphaned records found blocking FK creation\n";
    }
    
    return $orphanedFKs;
}

function removeOrphans($orphanedFKs)
{
    echo "\n🔧 Removing Orphaned Records\n";
    echo "============================\n\n";
    
    $totalRemoved = 0;
    
    foreach ($orphanedFKs as $fk) {
        $table = $fk['table'];
        $column = $fk['column'];
        $refTable = $fk['ref_table'];
        $refCol = $fk['ref_col'];
        
        echo "Removing orphans from {$table}.{$column}...\n";
        
        $removed = DB::statement(
            "DELETE t FROM `{$table}` t
             LEFT JOIN `{$refTable}` r ON t.`{$column}` = r.`{$refCol}`
             WHERE t.`{$column}` IS NOT NULL AND r.`{$refCol}` IS NULL"
        );
        
        $count = DB::selectOne("SELECT ROW_COUNT() as cnt");
        $removedCount = $count ? $count->cnt : 0;
        $totalRemoved += $removedCount;
        
        echo "  ✅ Removed {$removedCount} orphaned records\n";
    }
    
    echo "\n✅ Total removed: {$totalRemoved} orphaned records\n";
    
    return $totalRemoved;
}

