<?php

/**
 * Complete Schema Verification
 * 
 * Verifies:
 * 1. All tables in master migration exist in DB
 * 2. All tables in DB exist in master migration (excluding Laravel system tables)
 * 3. All FKs in DB match master migration (including onDelete rules)
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/verify_schema_complete.php';
 * >>> verifySchemaComplete('prod');
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function verifySchemaComplete($environment = null)
{
    $dbName = DB::connection()->getDatabaseName();
    $env = $environment ?: (str_contains($dbName, 'test') ? 'test' : (str_contains($dbName, 'prod') ? 'prod' : 'dev'));
    
    echo "🔍 Complete Schema Verification\n";
    echo "Environment: {$env}\n";
    echo "=====================================\n\n";
    
    $masterPath = base_path('database/migrations/2025_01_01_000000_create_master_tables.php');
    if (!file_exists($masterPath)) {
        echo "❌ Master migration not found: {$masterPath}\n";
        return false;
    }
    
    $masterContent = file_get_contents($masterPath);
    
    // Get tables from master migration
    preg_match_all("/Schema::(?:create|table)\s*\(['\"](\w+)['\"]/i", $masterContent, $matches);
    $masterTables = array_unique($matches[1]);
    sort($masterTables);
    
    // Get tables from database
    $dbTables = DB::select('SHOW TABLES');
    $tableKey = 'Tables_in_' . $dbName;
    $dbTableList = [];
    foreach ($dbTables as $row) {
        $dbTableList[] = $row->$tableKey;
    }
    sort($dbTableList);
    
    // Laravel system tables (expected, not in master)
    $laravelSystemTables = ['cache', 'cache_locks', 'failed_jobs', 'jobs', 'migrations', 'password_reset_tokens', 'sessions', 'personal_access_tokens'];
    $dbTableListExcludingLaravel = array_diff($dbTableList, $laravelSystemTables);
    
    // Check 1: All tables in master migration exist in DB
    echo "✅ CHECK 1: All tables in master migration exist in DB\n";
    echo "=====================================================\n";
    $missingTables = array_diff($masterTables, $dbTableList);
    if (count($missingTables) === 0) {
        echo "✅ PASS: All " . count($masterTables) . " master tables exist in DB\n\n";
    } else {
        echo "❌ FAIL: Missing " . count($missingTables) . " tables:\n";
        foreach ($missingTables as $table) {
            echo "  - {$table}\n";
        }
        echo "\n";
    }
    
    // Check 2: All tables in DB exist in master migration (excluding Laravel system tables)
    echo "✅ CHECK 2: All tables in DB exist in master migration (excluding Laravel system)\n";
    echo "==============================================================================\n";
    $extraTables = array_diff($dbTableListExcludingLaravel, $masterTables);
    if (count($extraTables) === 0) {
        echo "✅ PASS: All " . count($dbTableListExcludingLaravel) . " DB tables are in master migration\n\n";
    } else {
        echo "❌ FAIL: " . count($extraTables) . " extra tables in DB (not in master):\n";
        foreach ($extraTables as $table) {
            $rowCount = DB::table($table)->count();
            echo "  - {$table} ({$rowCount} rows)\n";
        }
        echo "\n";
    }
    
    // Check 3: All FKs in DB match master migration (including onDelete rules)
    echo "✅ CHECK 3: All FKs in DB match master migration (including onDelete rules)\n";
    echo "=========================================================================\n";
    
    // Parse FKs from master migration
    $masterFKs = parseFKsFromMasterComplete($masterContent);
    
    // Get FKs from database
    $dbFKs = DB::select("
        SELECT 
            kcu.TABLE_NAME,
            kcu.COLUMN_NAME,
            kcu.REFERENCED_TABLE_NAME,
            kcu.REFERENCED_COLUMN_NAME,
            kcu.CONSTRAINT_NAME,
            rc.DELETE_RULE
        FROM information_schema.KEY_COLUMN_USAGE kcu
        INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
            ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
            AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
        WHERE kcu.TABLE_SCHEMA = DATABASE()
        AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY kcu.TABLE_NAME, kcu.COLUMN_NAME
    ");
    
    // Build FK map from database
    $dbFKMap = [];
    foreach ($dbFKs as $fk) {
        $key = $fk->TABLE_NAME . '.' . $fk->COLUMN_NAME;
        $dbFKMap[$key] = [
            'references_table' => $fk->REFERENCED_TABLE_NAME,
            'references_column' => $fk->REFERENCED_COLUMN_NAME,
            'on_delete' => normalizeFKRuleComplete($fk->DELETE_RULE),
            'constraint_name' => $fk->CONSTRAINT_NAME,
        ];
    }
    
    // Check each master FK
    $missingFKs = [];
    $wrongFKs = [];
    $extraFKs = [];
    
    foreach ($masterFKs as $table => $fks) {
        foreach ($fks as $fk) {
            $key = $table . '.' . $fk['column'];
            if (!isset($dbFKMap[$key])) {
                $missingFKs[] = [
                    'table' => $table,
                    'column' => $fk['column'],
                    'references_table' => $fk['references_table'],
                    'references_column' => $fk['references_column'],
                    'on_delete' => $fk['on_delete'],
                ];
            } elseif ($dbFKMap[$key]['on_delete'] !== $fk['on_delete']) {
                $wrongFKs[] = [
                    'table' => $table,
                    'column' => $fk['column'],
                    'references_table' => $fk['references_table'],
                    'references_column' => $fk['references_column'],
                    'expected_on_delete' => $fk['on_delete'],
                    'current_on_delete' => $dbFKMap[$key]['on_delete'],
                ];
            }
        }
    }
    
    // Find extra FKs (in DB but not in master)
    $masterFKKeys = [];
    foreach ($masterFKs as $table => $fks) {
        foreach ($fks as $fk) {
            $masterFKKeys[] = $table . '.' . $fk['column'];
        }
    }
    
    foreach ($dbFKMap as $key => $fk) {
        if (!in_array($key, $masterFKKeys)) {
            $parts = explode('.', $key);
            $extraFKs[] = [
                'table' => $parts[0],
                'column' => $parts[1],
                'references_table' => $fk['references_table'],
                'references_column' => $fk['references_column'],
                'on_delete' => $fk['on_delete'],
            ];
        }
    }
    
    if (count($missingFKs) === 0 && count($wrongFKs) === 0 && count($extraFKs) === 0) {
        echo "✅ PASS: All " . count($dbFKs) . " FKs in DB match master migration perfectly\n\n";
    } else {
        if (count($missingFKs) > 0) {
            echo "❌ FAIL: " . count($missingFKs) . " missing FKs:\n";
            foreach ($missingFKs as $fk) {
                echo "  - {$fk['table']}.{$fk['column']} -> {$fk['references_table']}.{$fk['references_column']} ({$fk['on_delete']})\n";
            }
            echo "\n";
        }
        
        if (count($wrongFKs) > 0) {
            echo "❌ FAIL: " . count($wrongFKs) . " FKs with wrong onDelete rules:\n";
            foreach ($wrongFKs as $fk) {
                echo "  - {$fk['table']}.{$fk['column']}: expected {$fk['expected_on_delete']}, found {$fk['current_on_delete']}\n";
            }
            echo "\n";
        }
        
        if (count($extraFKs) > 0) {
            echo "⚠️  WARNING: " . count($extraFKs) . " extra FKs in DB (not in master):\n";
            foreach ($extraFKs as $fk) {
                echo "  - {$fk['table']}.{$fk['column']} -> {$fk['references_table']}.{$fk['references_column']} ({$fk['on_delete']})\n";
            }
            echo "\n";
        }
    }
    
    // Final summary
    echo "📊 VERIFICATION SUMMARY\n";
    echo "======================\n";
    $allPass = (count($missingTables) === 0 && count($extraTables) === 0 && count($missingFKs) === 0 && count($wrongFKs) === 0);
    
    if ($allPass) {
        echo "✅ ALL CHECKS PASSED!\n";
        echo "   - All master tables exist in DB\n";
        echo "   - All DB tables are in master (excluding Laravel system)\n";
        echo "   - All FKs match master migration (including onDelete rules)\n";
    } else {
        echo "❌ SOME CHECKS FAILED\n";
        echo "   - Missing tables: " . count($missingTables) . "\n";
        echo "   - Extra tables: " . count($extraTables) . "\n";
        echo "   - Missing FKs: " . count($missingFKs) . "\n";
        echo "   - Wrong FKs: " . count($wrongFKs) . "\n";
    }
    
    return $allPass;
}

function parseFKsFromMasterComplete($masterContent)
{
    $fks = [];
    $currentTable = null;
    
    // Split by table definitions
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
            $hasNullOnDelete = preg_match(
                "/\\\$table->foreign\s*\(\s*['\"]" . preg_quote($fk[1], '/') . "['\"]\s*\)\s*->references\s*\(\s*['\"]" . preg_quote($fk[2], '/') . "['\"]\s*\)\s*->on\s*\(\s*['\"]" . preg_quote($fk[3], '/') . "['\"]\s*\)\s*->nullOnDelete\s*\(\s*\)/s",
                $tableDef
            );
            
            $onDelete = 'RESTRICT';
            if ($hasNullOnDelete) {
                $onDelete = 'SET NULL';
            } elseif (isset($fk[4]) && $fk[4] !== '') {
                $onDelete = strtoupper($fk[4]);
            }
            
            if (!isset($fks[$tableName])) {
                $fks[$tableName] = [];
            }
            
            $fks[$tableName][] = [
                'column' => $fk[1],
                'references_table' => $fk[3],
                'references_column' => $fk[2],
                'on_delete' => normalizeFKRuleComplete($onDelete),
            ];
        }
    }
    
    return $fks;
}

function normalizeFKRuleComplete($rule)
{
    $rule = strtoupper(trim($rule));
    if ($rule === 'NO ACTION') {
        return 'RESTRICT';
    }
    return $rule;
}

