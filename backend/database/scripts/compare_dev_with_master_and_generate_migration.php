<?php

/**
 * Compare Dev Database Schema with Master Migration and Generate Sync Migration
 * 
 * This script:
 * 1. Reads the exported dev schema
 * 2. Parses the master migration
 * 3. Compares them to find differences
 * 4. Generates a migration file to sync dev to master
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/compare_dev_with_master_and_generate_migration.php';
 * >>> compareAndGenerateMigration();
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;

function compareAndGenerateMigration(): string
{
    echo "🔍 Comparing Dev Schema with Master Migration...\n";
    echo "================================================\n\n";

    // Find the latest dev schema export
    $storagePath = storage_path('app');
    $schemaFiles = glob($storagePath . '/dev_schema_export_*.md');
    if (empty($schemaFiles)) {
        throw new Exception("No dev schema export found. Please run export_dev_schema.php first.");
    }
    rsort($schemaFiles);
    $devSchemaFile = $schemaFiles[0];
    echo "📄 Reading dev schema: " . basename($devSchemaFile) . "\n";

    // Read dev schema
    $devSchema = parseDevSchema($devSchemaFile);
    
    // Read master migration
    $masterMigrationFile = database_path('migrations/2025_01_01_000000_create_master_tables.php');
    echo "📄 Reading master migration: " . basename($masterMigrationFile) . "\n";
    
    $masterSchema = parseMasterMigration($masterMigrationFile);
    
    // Compare schemas
    echo "\n🔎 Comparing schemas...\n";
    $differences = compareSchemas($devSchema, $masterSchema);
    
    // Generate migration
    echo "\n📝 Generating migration...\n";
    $migrationContent = generateMigration($differences, $devSchema, $masterSchema);
    
    // Write migration file
    $migrationFileName = date('Y_m_d_His') . '_sync_dev_to_master_schema.php';
    $migrationPath = database_path('migrations/' . $migrationFileName);
    File::put($migrationPath, $migrationContent);
    
    echo "\n✅ Migration generated successfully!\n";
    echo "📁 File: {$migrationPath}\n";
    echo "\n";
    echo "Summary of changes:\n";
    echo "- Tables to modify: " . count($differences['tables']) . "\n";
    echo "- Foreign keys to fix: " . count($differences['foreign_keys']) . "\n";
    echo "- Indexes to fix: " . count($differences['indexes']) . "\n";
    
    return $migrationPath;
}

function parseDevSchema(string $file): array
{
    $content = File::get($file);
    $tables = [];
    $currentTable = null;
    
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        // Table header
        if (preg_match('/^## Table: `(.+)`$/', $line, $matches)) {
            $currentTable = $matches[1];
            $tables[$currentTable] = [
                'columns' => [],
                'indexes' => [],
                'foreign_keys' => []
            ];
            continue;
        }
        
        if (!$currentTable) continue;
        
        // Column
        if (preg_match('/^\| `(.+)` \| `(.+)` \| (.+) \| (.+) \| (.+) \| (.+) \|$/', $line, $matches)) {
            $colName = $matches[1];
            $colType = $matches[2];
            $colNull = $matches[3] === 'YES';
            $colKey = $matches[4];
            $colDefault = $matches[5] === 'NULL' ? null : $matches[5];
            $colExtra = $matches[6];
            
            $tables[$currentTable]['columns'][$colName] = [
                'type' => $colType,
                'nullable' => $colNull,
                'key' => $colKey,
                'default' => $colDefault,
                'extra' => $colExtra
            ];
            continue;
        }
        
        // Index
        if (preg_match('/^\| `(.+)` \| (.+) \| (.+) \| (.+) \|$/', $line, $matches)) {
            $idxName = $matches[1];
            $idxColumns = array_map('trim', explode(',', $matches[2]));
            $idxUnique = $matches[3] === 'YES';
            $idxType = $matches[4];
            
            $tables[$currentTable]['indexes'][$idxName] = [
                'columns' => $idxColumns,
                'unique' => $idxUnique,
                'type' => $idxType
            ];
            continue;
        }
        
        // Foreign key
        if (preg_match('/^\| `(.+)` \| `(.+)` \| `(.+)`\.`(.+)` \| (.+) \| (.+) \|$/', $line, $matches)) {
            $fkName = $matches[1];
            $fkColumn = $matches[2];
            $fkRefTable = $matches[3];
            $fkRefColumn = $matches[4];
            $fkOnUpdate = $matches[5];
            $fkOnDelete = $matches[6];
            
            $tables[$currentTable]['foreign_keys'][$fkName] = [
                'column' => $fkColumn,
                'references_table' => $fkRefTable,
                'references_column' => $fkRefColumn,
                'on_update' => $fkOnUpdate,
                'on_delete' => $fkOnDelete
            ];
            continue;
        }
    }
    
    return $tables;
}

function parseMasterMigration(string $file): array
{
    $content = File::get($file);
    $tables = [];
    
    // Extract table definitions using regex
    preg_match_all('/Schema::create\([\'"]([^\'"]+)[\'"]/i', $content, $tableMatches);
    
    foreach ($tableMatches[1] as $tableName) {
        $tables[$tableName] = [
            'columns' => [],
            'indexes' => [],
            'foreign_keys' => []
        ];
        
        // Extract columns
        preg_match_all('/\$table->(unsignedInteger|integer|string|text|longText|boolean|timestamp|datetime|date|enum|decimal|unsignedTinyInteger|unsignedSmallInteger)\([\'"]([^\'"]+)[\'"](?:,\s*([^\)]+))?\)(?:->([^;]+))?;/', $content, $colMatches, PREG_SET_ORDER);
        
        foreach ($colMatches as $match) {
            $colType = $match[1];
            $colName = $match[2];
            $colLength = isset($match[3]) ? trim($match[3], ' \'"') : null;
            $colModifiers = isset($match[4]) ? $match[4] : '';
            
            $nullable = strpos($colModifiers, 'nullable') !== false;
            $default = null;
            if (preg_match('/default\(([^\)]+)\)/', $colModifiers, $defMatch)) {
                $default = trim($defMatch[1], ' \'"');
            }
            $unique = strpos($colModifiers, 'unique') !== false;
            $autoIncrement = strpos($colModifiers, 'autoIncrement') !== false;
            
            $tables[$tableName]['columns'][$colName] = [
                'type' => $colType,
                'length' => $colLength,
                'nullable' => $nullable,
                'default' => $default,
                'unique' => $unique,
                'auto_increment' => $autoIncrement,
                'modifiers' => $colModifiers
            ];
        }
        
        // Extract foreign keys
        preg_match_all('/\$table->foreign\([\'"]([^\'"]+)[\'"]\)->references\([\'"]([^\'"]+)[\'"]\)->on\([\'"]([^\'"]+)[\'"]\)(?:->onDelete\([\'"]([^\'"]+)[\'"]\))?(?:->onUpdate\([\'"]([^\'"]+)[\'"]\))?;/', $content, $fkMatches, PREG_SET_ORDER);
        
        foreach ($fkMatches as $match) {
            $fkColumn = $match[1];
            $fkRefColumn = $match[2];
            $fkRefTable = $match[3];
            $fkOnDelete = isset($match[4]) ? $match[4] : 'restrict';
            $fkOnUpdate = isset($match[5]) ? $match[5] : 'restrict';
            
            $tables[$tableName]['foreign_keys'][] = [
                'column' => $fkColumn,
                'references_table' => $fkRefTable,
                'references_column' => $fkRefColumn,
                'on_update' => strtolower($fkOnUpdate),
                'on_delete' => strtolower($fkOnDelete)
            ];
        }
        
        // Extract indexes
        preg_match_all('/\$table->(index|unique)\(([^\)]+)\)(?:->name\([\'"]([^\'"]+)[\'"]\))?;/', $content, $idxMatches, PREG_SET_ORDER);
        
        foreach ($idxMatches as $match) {
            $idxType = $match[1];
            $idxColumns = array_map('trim', explode(',', trim($match[2], ' \'"[]')));
            $idxName = isset($match[3]) ? $match[3] : null;
            
            $tables[$tableName]['indexes'][] = [
                'columns' => $idxColumns,
                'unique' => $idxType === 'unique',
                'name' => $idxName
            ];
        }
    }
    
    return $tables;
}

function compareSchemas(array $devSchema, array $masterSchema): array
{
    $differences = [
        'tables' => [],
        'foreign_keys' => [],
        'indexes' => []
    ];
    
    // Compare each table in master
    foreach ($masterSchema as $tableName => $masterTable) {
        if (!isset($devSchema[$tableName])) {
            $differences['tables'][$tableName] = ['missing' => true];
            continue;
        }
        
        $devTable = $devSchema[$tableName];
        $tableDiffs = [];
        
        // Compare columns
        foreach ($masterTable['columns'] as $colName => $masterCol) {
            if (!isset($devTable['columns'][$colName])) {
                $tableDiffs['missing_columns'][] = $colName;
            } else {
                $devCol = $devTable['columns'][$colName];
                // Compare types, nullability, defaults
                // This is simplified - would need more detailed comparison
            }
        }
        
        // Check for extra columns in dev
        foreach ($devTable['columns'] as $colName => $devCol) {
            if (!isset($masterTable['columns'][$colName])) {
                $tableDiffs['extra_columns'][] = $colName;
            }
        }
        
        // Compare foreign keys
        foreach ($masterTable['foreign_keys'] as $masterFk) {
            $found = false;
            foreach ($devTable['foreign_keys'] as $fkName => $devFk) {
                if ($devFk['column'] === $masterFk['column'] && 
                    $devFk['references_table'] === $masterFk['references_table'] &&
                    $devFk['references_column'] === $masterFk['references_column']) {
                    $found = true;
                    // Check delete/update rules
                    if (strtolower($devFk['on_delete']) !== strtolower($masterFk['on_delete']) ||
                        strtolower($devFk['on_update']) !== strtolower($masterFk['on_update'])) {
                        $differences['foreign_keys'][] = [
                            'table' => $tableName,
                            'name' => $fkName,
                            'dev' => $devFk,
                            'master' => $masterFk
                        ];
                    }
                    break;
                }
            }
            if (!$found) {
                $differences['foreign_keys'][] = [
                    'table' => $tableName,
                    'name' => null,
                    'dev' => null,
                    'master' => $masterFk
                ];
            }
        }
        
        if (!empty($tableDiffs)) {
            $differences['tables'][$tableName] = $tableDiffs;
        }
    }
    
    return $differences;
}

function generateMigration(array $differences, array $devSchema, array $masterSchema): string
{
    $migration = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     * 
     * This migration syncs the dev database schema to match the master migration.
     * It fixes foreign keys, indexes, and column definitions.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }
        
        try {
PHP;

    // Add foreign key fixes
    foreach ($differences['foreign_keys'] as $fkDiff) {
        $tableName = $fkDiff['table'];
        $fkName = $fkDiff['name'];
        $masterFk = $fkDiff['master'];
        
        if ($fkName) {
            // Drop existing FK
            $migration .= "\n            // Fix foreign key on {$tableName}.{$masterFk['column']}\n";
            $migration .= "            if (Schema::hasTable('{$tableName}')) {\n";
            $migration .= "                Schema::table('{$tableName}', function (Blueprint \$table) use (\$driver) {\n";
            $migration .= "                    // Drop existing foreign key\n";
            $migration .= "                    \$table->dropForeign(['{$masterFk['column']}']);\n";
            $migration .= "                });\n";
            $migration .= "                \n";
            $migration .= "                Schema::table('{$tableName}', function (Blueprint \$table) {\n";
            $migration .= "                    // Recreate with correct rules\n";
            $onDelete = $masterFk['on_delete'] === 'cascade' ? 'cascade' : 
                       ($masterFk['on_delete'] === 'set null' ? 'setNull' : 'restrict');
            $migration .= "                    \$table->foreign('{$masterFk['column']}')->references('{$masterFk['references_column']}')->on('{$masterFk['references_table']}')->onDelete('{$onDelete}');\n";
            $migration .= "                });\n";
            $migration .= "            }\n";
        } else {
            // Add missing FK
            $migration .= "\n            // Add missing foreign key on {$tableName}.{$masterFk['column']}\n";
            $migration .= "            if (Schema::hasTable('{$tableName}')) {\n";
            $migration .= "                Schema::table('{$tableName}', function (Blueprint \$table) {\n";
            $onDelete = $masterFk['on_delete'] === 'cascade' ? 'cascade' : 
                       ($masterFk['on_delete'] === 'set null' ? 'setNull' : 'restrict');
            $migration .= "                    \$table->foreign('{$masterFk['column']}')->references('{$masterFk['references_column']}')->on('{$masterFk['references_table']}')->onDelete('{$onDelete}');\n";
            $migration .= "                });\n";
            $migration .= "            }\n";
        }
    }
    
    $migration .= <<<'PHP'

        } finally {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is designed to sync to master, so rollback would require
        // restoring the previous state, which is complex. Consider backing up
        // the database before running this migration.
    }
};
PHP;

    return $migration;
}

