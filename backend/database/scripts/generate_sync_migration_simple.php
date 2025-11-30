<?php

/**
 * Simple approach: Manually extract expected tables from master migration
 * and compare with Dev schema to generate sync migration
 * 
 * Usage: php artisan tinker
 * >>> include 'database/scripts/generate_sync_migration_simple.php';
 * >>> generateSyncMigrationSimple();
 */

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

function generateSyncMigrationSimple()
{
    echo "🔍 Generating Sync Migration (Simple Approach)...\n";
    echo "==================================================\n\n";
    
    // Read dev schema
    $devSchemaPath = dirname(base_path()) . '/dev_schema.md';
    if (!file_exists($devSchemaPath)) {
        echo "❌ Dev schema export not found: {$devSchemaPath}\n";
        echo "   Run export_dev_schema.php first!\n";
        return;
    }
    $devSchema = parseDevSchemaSimple(file_get_contents($devSchemaPath));
    
    // Read master migration
    $masterPath = base_path('database/migrations/2025_01_01_000000_create_master_tables.php');
    if (!file_exists($masterPath)) {
        echo "❌ Master migration not found: {$masterPath}\n";
        return;
    }
    $masterContent = file_get_contents($masterPath);
    
    // List of all tables that should exist (from master migration)
    // Both m_ tables and regular tables
    $expectedTables = [
        // m_ tables (always recreated, but we still need to check structure)
        'm_season', 'm_level', 'm_news', 'm_room_type_group', 'm_room_type',
        'm_first_program', 'm_parameter', 'm_parameter_condition', 'm_activity_type',
        'm_activity_type_detail', 'm_insert_point', 'm_role', 'm_visibility', 'm_supported_plan',
        // Regular tables
        'regional_partner', 'event', 'contao_public_rounds', 'slideshow', 'slide',
        'publication', 'user', 'news_user', 'user_regional_partner', 'room',
        'room_type_room', 'team', 'plan', 's_generator', 's_one_link_access',
        'team_plan', 'plan_param_value', 'match', 'extra_block', 'activity_group',
        'activity', 'logo', 'event_logo', 'table_event', 'q_plan', 'q_plan_team', 'q_run'
    ];
    
    // Extract master schema for each table
    $masterSchema = [];
    foreach ($expectedTables as $tableName) {
        $tableDef = extractTableDefinitionFromMaster($masterContent, $tableName);
        if ($tableDef) {
            $masterSchema[$tableName] = $tableDef;
        }
    }
    
    // Compare schemas
    $differences = compareSchemasSimple($masterSchema, $devSchema, $expectedTables);
    
    // Generate migration
    $migrationContent = generateSyncMigrationContent($differences, $masterSchema, $devSchema);
    
    // Write migration file
    $migrationPath = base_path('database/migrations/' . date('Y_m_d_His') . '_sync_dev_to_master.php');
    file_put_contents($migrationPath, $migrationContent);
    
    echo "\n✅ Sync migration generated!\n";
    echo "📁 File: {$migrationPath}\n";
    echo "\n";
    echo "Summary of changes:\n";
    echo "===================\n";
    displayDifferencesSimple($differences);
    
    return $migrationPath;
}

function extractTableDefinitionFromMaster($content, $tableName)
{
    // Find Schema::create for this table - search for the pattern
    $searchPattern = "Schema::create('{$tableName}'";
    $altPattern = 'Schema::create("{$tableName}"';
    
    $pos = strpos($content, $searchPattern);
    if ($pos === false) {
        $pos = strpos($content, $altPattern);
    }
    if ($pos === false) {
        return null;
    }
    
    // Find the function definition after Schema::create
    $funcPos = strpos($content, "function (Blueprint", $pos);
    if ($funcPos === false) {
        return null;
    }
    
    // Find the opening brace of the function
    $braceStart = strpos($content, "{", $funcPos);
    if ($braceStart === false) {
        return null;
    }
    
    // Find matching closing brace
    $braceCount = 1;
    $pos = $braceStart + 1;
    while ($braceCount > 0 && $pos < strlen($content)) {
        if ($content[$pos] === '{') {
            $braceCount++;
        } elseif ($content[$pos] === '}') {
            $braceCount--;
        }
        if ($braceCount > 0) {
            $pos++;
        }
    }
    
    if ($braceCount !== 0) {
        return null; // Unmatched braces
    }
    
    $tableDef = substr($content, $braceStart + 1, $pos - $braceStart - 1);
    
    return parseTableDefinitionSimple($tableDef, $tableName);
}

function parseTableDefinitionSimple($tableDef, $tableName)
{
    $table = [
        'columns' => [],
        'foreign_keys' => [],
        'indexes' => []
    ];
    
    // Extract columns - look for $table->method('name', ...)
    // Handle enum separately first, then other columns
    // Special handling for enum: enum('col', ['val1', 'val2'])
    preg_match_all(
        "/\\\$table->enum\s*\(\s*['\"]([^'\"]+)['\"]\s*,\s*(\[[^\]]+\])\s*\)\s*(.*?)\s*;/s",
        $tableDef,
        $enumMatches,
        PREG_SET_ORDER
    );
    
    foreach ($enumMatches as $match) {
        $colName = $match[1];
        $enumArray = $match[2];
        $modifiers = isset($match[3]) ? $match[3] : '';
        
        // Extract enum values
        preg_match_all("/['\"]([^'\"]+)['\"]/", $enumArray, $valueMatches);
        $enumValues = $valueMatches[1];
        $enumType = "enum('" . implode("','", $enumValues) . "')";
        
        // Check nullable
        $isNullable = false;
        if (preg_match('/->nullable\s*\(\s*\)/', $modifiers)) {
            $isNullable = true;
        }
        
        $table['columns'][$colName] = [
            'name' => $colName,
            'type' => $enumType,
            'nullable' => $isNullable,
            'default' => extractDefaultSimple($modifiers),
            'auto_increment' => false,
        ];
    }
    
    // Now extract other columns (excluding enums we already processed)
    preg_match_all(
        "/\\\$table->(\w+)\s*\(\s*['\"]([^'\"]+)['\"]\s*(?:,\s*(\d+)(?:\s*,\s*(\d+))?)?\s*\)\s*(.*?)\s*;/s",
        $tableDef,
        $matches,
        PREG_SET_ORDER
    );
    
    foreach ($matches as $match) {
        $method = $match[1];
        $colName = $match[2];
        $length = isset($match[3]) && $match[3] !== '' ? $match[3] : null;
        $modifiers = isset($match[5]) ? $match[5] : '';
        
        // Skip non-column methods and enums (already processed)
        if (in_array($method, ['foreign', 'index', 'unique', 'drop', 'enum'])) {
            continue;
        }
        
        // Skip if we already processed this column (enum)
        if (isset($table['columns'][$colName])) {
            continue;
        }
        
        // Check nullable: default is NOT NULL unless ->nullable() is explicitly present
        // Note: In Laravel, columns are NOT NULL by default. Only ->nullable() makes them nullable.
        $isNullable = false; // Default to NOT NULL (Laravel's default)
        if (preg_match('/->nullable\s*\(\s*\)/', $modifiers)) {
            $isNullable = true; // Explicitly nullable
        } elseif (preg_match('/->nullable\s*\(\s*false\s*\)/', $modifiers)) {
            $isNullable = false; // Explicitly NOT NULL
        } elseif (strpos($modifiers, 'nullable(false)') !== false) {
            $isNullable = false; // Explicitly NOT NULL
        } elseif (strpos($modifiers, 'nullable()') !== false || strpos($modifiers, '->nullable()') !== false) {
            $isNullable = true; // Explicitly nullable
        }
        
        $table['columns'][$colName] = [
            'name' => $colName,
            'type' => mapLaravelTypeToMySQLSimple($method, $length),
            'nullable' => $isNullable,
            'default' => extractDefaultSimple($modifiers),
            'auto_increment' => strpos($modifiers, 'autoIncrement') !== false || strpos($modifiers, 'auto_increment') !== false,
        ];
    }
    
    // Extract foreign keys
    // Pattern: $table->foreign('col')->references('ref_col')->on('ref_table')->onDelete('rule')
    // Use 's' flag to match across newlines
    preg_match_all(
        "/\\\$table->foreign\s*\(\s*['\"]([^'\"]+)['\"]\s*\)\s*->references\s*\(\s*['\"]([^'\"]+)['\"]\s*\)\s*->on\s*\(\s*['\"]([^'\"]+)['\"]\s*\)\s*(?:->onDelete\s*\(\s*['\"]([^'\"]+)['\"]\s*\))?/s",
        $tableDef,
        $fkMatches,
        PREG_SET_ORDER
    );
    
    foreach ($fkMatches as $fk) {
        $table['foreign_keys'][] = [
            'column' => $fk[1],
            'references_table' => $fk[3],
            'references_column' => $fk[2],
            'on_delete' => strtoupper(isset($fk[4]) && $fk[4] !== '' ? $fk[4] : 'RESTRICT'),
        ];
    }
    
    // Extract indexes
    // Pattern: $table->index(['col1', 'col2']) or $table->unique(['col'])
    preg_match_all(
        "/\\\$table->(index|unique)\s*\(\s*\[(.*?)\]\s*\)/s",
        $tableDef,
        $idxMatches,
        PREG_SET_ORDER
    );
    
    foreach ($idxMatches as $idx) {
        $columns = array_map('trim', explode(',', preg_replace("/['\"]/", '', $idx[2])));
        $table['indexes'][] = [
            'name' => implode('_', $columns) . ($idx[1] === 'unique' ? '_unique' : '_index'),
            'columns' => $columns,
            'unique' => $idx[1] === 'unique',
        ];
    }
    
    // Extract named indexes: $table->index('col', 'index_name')
    preg_match_all(
        "/\\\$table->(index|unique)\s*\(\s*['\"]([^'\"]+)['\"]\s*(?:,\s*['\"]([^'\"]+)['\"])?\s*\)/s",
        $tableDef,
        $namedIdxMatches,
        PREG_SET_ORDER
    );
    
    foreach ($namedIdxMatches as $idx) {
        $colName = $idx[2];
        $indexName = isset($idx[3]) && $idx[3] !== '' ? $idx[3] : ($colName . ($idx[1] === 'unique' ? '_unique' : '_index'));
        $table['indexes'][] = [
            'name' => $indexName,
            'columns' => [$colName],
            'unique' => $idx[1] === 'unique',
        ];
    }
    
    return $table;
}

function mapLaravelTypeToMySQLSimple($laravelType, $length = null)
{
    $mapping = [
        'unsignedInteger' => 'int(10) unsigned',
        'integer' => 'int(11)',
        'unsignedSmallInteger' => 'smallint(5) unsigned',
        'unsignedTinyInteger' => 'tinyint(3) unsigned',
        'string' => $length ? "varchar({$length})" : 'varchar(255)',
        'text' => 'text',
        'longText' => 'longtext',
        'boolean' => 'tinyint(1)',
        'timestamp' => 'timestamp',
        'date' => 'date',
        'datetime' => 'datetime',
        'decimal' => 'decimal(8,2)',
    ];
    
    return $mapping[$laravelType] ?? $laravelType;
}

function extractDefaultSimple($modifiers)
{
    if (preg_match("/->default\(([^)]+)\)/", $modifiers, $match)) {
        $default = trim($match[1], "'\"");
        if ($default === 'true') return 1;
        if ($default === 'false') return 0;
        return $default;
    }
    return null;
}

function parseDevSchemaSimple($content)
{
    $schema = [];
    $lines = explode("\n", $content);
    $currentTable = null;
    $inColumns = false;
    $inForeignKeys = false;
    $inIndexes = false;
    
    foreach ($lines as $line) {
        if (preg_match("/^## Table: `([^`]+)`/", $line, $match)) {
            $currentTable = $match[1];
            $schema[$currentTable] = ['columns' => [], 'foreign_keys' => [], 'indexes' => []];
            $inColumns = false;
            $inForeignKeys = false;
            $inIndexes = false;
            continue;
        }
        
        if (strpos($line, '### Columns') !== false) {
            $inColumns = true;
            $inForeignKeys = false;
            continue;
        }
        if (strpos($line, '### Foreign Keys') !== false) {
            $inColumns = false;
            $inForeignKeys = true;
            $inIndexes = false;
            continue;
        }
        if (strpos($line, '### Indexes') !== false) {
            $inColumns = false;
            $inForeignKeys = false;
            $inIndexes = true;
            continue;
        }
        
        if (!$currentTable) continue;
        
        if ($inColumns && preg_match("/^\| `([^`]+)` \| `([^`]+)` \| (YES|NO) \| ([^|]+) \| ([^|]*) \| ([^|]+) \|/", $line, $match)) {
            $default = trim($match[5]);
            if ($default === '' || $default === 'NULL') $default = null;
            $schema[$currentTable]['columns'][$match[1]] = [
                'name' => $match[1],
                'type' => $match[2],
                'nullable' => $match[3] === 'YES',
                'default' => $default,
            ];
        }
        
        if ($inForeignKeys && preg_match("/^\| `([^`]+)` \| `([^`]+)` \| `([^`]+)`\.`([^`]+)` \| ([^|]+) \| ([^|]+) \|/", $line, $match)) {
            $schema[$currentTable]['foreign_keys'][] = [
                'column' => $match[2],
                'references_table' => $match[3],
                'references_column' => $match[4],
                'on_delete' => strtoupper(trim($match[6])),
            ];
        }
        
        // Parse indexes
        if ($inIndexes && preg_match("/^\| `([^`]+)` \| ([^|]+) \| (YES|NO) \| ([^|]+) \|/", $line, $match)) {
            $columns = array_map('trim', explode(',', $match[2]));
            $schema[$currentTable]['indexes'][] = [
                'name' => $match[1],
                'columns' => $columns,
                'unique' => $match[3] === 'YES',
            ];
        }
    }
    
    return $schema;
}

function compareSchemasSimple($master, $dev, $expectedTables)
{
    $differences = [
        'missing_tables' => [],
        'missing_columns' => [],
        'extra_columns' => [],
        'type_mismatches' => [],
        'nullable_mismatches' => [],
        'default_mismatches' => [],
        'missing_foreign_keys' => [],
        'wrong_foreign_keys' => [],
        'missing_indexes' => [],
        'extra_indexes' => [],
    ];
    
    foreach ($expectedTables as $tableName) {
        if (!isset($master[$tableName])) {
            continue; // Can't compare if not in master
        }
        
        $masterTable = $master[$tableName];
        $devTable = $dev[$tableName] ?? null;
        
        if (!$devTable) {
            $differences['missing_tables'][] = $tableName;
            continue;
        }
        
        // Compare columns
        foreach ($masterTable['columns'] as $colName => $masterCol) {
            if (!isset($devTable['columns'][$colName])) {
                $differences['missing_columns'][] = [
                    'table' => $tableName,
                    'column' => $colName,
                    'definition' => $masterCol,
                ];
            } else {
                $devCol = $devTable['columns'][$colName];
                
                // Compare types (normalize)
                $masterType = normalizeTypeSimple($masterCol['type']);
                $devType = normalizeTypeSimple($devCol['type']);
                if ($masterType !== $devType) {
                    $differences['type_mismatches'][] = [
                        'table' => $tableName,
                        'column' => $colName,
                        'master' => $masterCol['type'],
                        'dev' => $devCol['type'],
                    ];
                }
                
                // Compare nullable
                if ($masterCol['nullable'] !== $devCol['nullable']) {
                    $differences['nullable_mismatches'][] = [
                        'table' => $tableName,
                        'column' => $colName,
                        'master' => $masterCol['nullable'],
                        'dev' => $devCol['nullable'],
                    ];
                }
                
                // Compare defaults (normalize for comparison)
                $masterDefault = normalizeDefault($masterCol['default'] ?? null);
                $devDefault = normalizeDefault($devCol['default'] ?? null);
                if ($masterDefault !== $devDefault) {
                    $differences['default_mismatches'][] = [
                        'table' => $tableName,
                        'column' => $colName,
                        'master' => $masterCol['default'] ?? null,
                        'dev' => $devCol['default'] ?? null,
                    ];
                }
            }
        }
        
        // Find extra columns (in Dev but not in Master)
        foreach ($devTable['columns'] as $colName => $devCol) {
            if (!isset($masterTable['columns'][$colName])) {
                $differences['extra_columns'][] = [
                    'table' => $tableName,
                    'column' => $colName,
                ];
            }
        }
        
        // Compare foreign keys
        foreach ($masterTable['foreign_keys'] as $masterFk) {
            $found = false;
            foreach ($devTable['foreign_keys'] ?? [] as $devFk) {
                if ($devFk['column'] === $masterFk['column'] &&
                    $devFk['references_table'] === $masterFk['references_table']) {
                    $found = true;
                    // Normalize: NO ACTION and RESTRICT are functionally the same in MySQL
                    $masterRule = normalizeFKRule($masterFk['on_delete']);
                    $devRule = normalizeFKRule($devFk['on_delete']);
                    if ($devRule !== $masterRule) {
                        $differences['wrong_foreign_keys'][] = [
                            'table' => $tableName,
                            'column' => $masterFk['column'],
                            'master' => $masterFk['on_delete'],
                            'dev' => $devFk['on_delete'],
                            'fk' => $masterFk, // Include full FK details for recreation
                        ];
                    }
                    break;
                }
            }
            if (!$found) {
                $differences['missing_foreign_keys'][] = [
                    'table' => $tableName,
                    'fk' => $masterFk,
                ];
            }
        }
        
        // Compare indexes (if we have index data)
        if (isset($masterTable['indexes']) && isset($devTable['indexes'])) {
            $masterIndexNames = array_column($masterTable['indexes'], 'name');
            $devIndexNames = array_column($devTable['indexes'] ?? [], 'name');
            
            foreach ($masterTable['indexes'] as $masterIdx) {
                $found = false;
                foreach ($devTable['indexes'] ?? [] as $devIdx) {
                    if ($devIdx['name'] === $masterIdx['name'] ||
                        (implode(',', $devIdx['columns']) === implode(',', $masterIdx['columns']) &&
                         $devIdx['unique'] === $masterIdx['unique'])) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $differences['missing_indexes'][] = [
                        'table' => $tableName,
                        'index' => $masterIdx,
                    ];
                }
            }
            
            // Find extra indexes
            foreach ($devTable['indexes'] ?? [] as $devIdx) {
                // Skip primary key
                if ($devIdx['name'] === 'PRIMARY') continue;
                
                $found = false;
                foreach ($masterTable['indexes'] as $masterIdx) {
                    if ($masterIdx['name'] === $devIdx['name'] ||
                        (implode(',', $masterIdx['columns']) === implode(',', $devIdx['columns']) &&
                         $masterIdx['unique'] === $devIdx['unique'])) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $differences['extra_indexes'][] = [
                        'table' => $tableName,
                        'index' => $devIdx,
                    ];
                }
            }
        }
    }
    
    return $differences;
}

function normalizeDefault($default)
{
    if ($default === null || $default === 'NULL') return null;
    if ($default === '0' || $default === 0) return '0';
    if ($default === '1' || $default === 1) return '1';
    if ($default === 'false' || $default === false) return false;
    if ($default === 'true' || $default === true) return true;
    return (string)$default;
}

function normalizeTypeSimple($type)
{
    $type = strtolower($type);
    $type = preg_replace('/\(\d+\)/', '', $type);
    $type = preg_replace('/\(\d+,\d+\)/', '', $type);
    return trim($type);
}

function normalizeFKRule($rule)
{
    // Normalize FK delete rules: NO ACTION and RESTRICT are functionally the same in MySQL
    $rule = strtoupper(trim($rule));
    if ($rule === 'NO ACTION') {
        return 'RESTRICT';
    }
    return $rule;
}

/**
 * Helper: Get all FKs that reference a specific table.column
 */
function getFKsReferencingColumn($devSchema, $tableName, $columnName)
{
    $referencingFKs = [];
    foreach ($devSchema as $table => $tableDef) {
        if (isset($tableDef['foreign_keys'])) {
            foreach ($tableDef['foreign_keys'] as $fk) {
                if ($fk['references_table'] === $tableName && $fk['references_column'] === $columnName) {
                    $referencingFKs[] = [
                        'table' => $table,
                        'column' => $fk['column'],
                        'constraint_name' => $fk['constraint_name'] ?? "{$table}_{$fk['column']}_foreign",
                        'fk' => $fk,
                    ];
                }
            }
        }
    }
    return $referencingFKs;
}

/**
 * Helper: Get all FKs on a specific column
 */
function getFKsOnColumn($devSchema, $tableName, $columnName)
{
    if (!isset($devSchema[$tableName]['foreign_keys'])) {
        return [];
    }
    
    $fks = [];
    foreach ($devSchema[$tableName]['foreign_keys'] as $fk) {
        if ($fk['column'] === $columnName) {
            $fks[] = [
                'constraint_name' => $fk['constraint_name'] ?? "{$tableName}_{$columnName}_foreign",
                'fk' => $fk,
            ];
        }
    }
    return $fks;
}

function generateSyncMigrationContent($differences, $masterSchema, $devSchema)
{
    $migration = "<?php\n\n";
    $migration .= "use Illuminate\\Database\\Migrations\\Migration;\n";
    $migration .= "use Illuminate\\Database\\Schema\\Blueprint;\n";
    $migration .= "use Illuminate\\Support\\Facades\\Schema;\n";
    $migration .= "use Illuminate\\Support\\Facades\\DB;\n\n";
    $migration .= "return new class extends Migration\n";
    $migration .= "{\n";
    $migration .= "    /**\n";
    $migration .= "     * Sync Dev database to match master migration schema.\n";
    $migration .= "     */\n";
    $migration .= "    public function up(): void\n";
    $migration .= "    {\n";
    $migration .= "        \$driver = DB::connection()->getDriverName();\n";
    $migration .= "        if (\$driver === 'mysql' || \$driver === 'mariadb') {\n";
    $migration .= "            DB::statement('SET FOREIGN_KEY_CHECKS=0;');\n";
    $migration .= "        }\n\n";
    
    // Handle missing tables first (need to create them)
    if (!empty($differences['missing_tables'])) {
        $migration .= "        // Create missing tables\n";
        foreach ($differences['missing_tables'] as $tableName) {
            $migration .= "        // TODO: Create table {$tableName} (copy from master migration)\n";
            $migration .= "        // Note: This requires copying the full table definition from master migration\n";
        }
        $migration .= "\n";
    }
    
    // Group changes by table
    $tableChanges = [];
    
    foreach ($differences['missing_columns'] as $diff) {
        $table = $diff['table'];
        if (!isset($tableChanges[$table])) {
            $tableChanges[$table] = ['add' => [], 'alter' => [], 'fks' => [], 'drop_cols' => [], 'indexes' => []];
        }
        $tableChanges[$table]['add'][] = $diff;
    }
    
    foreach ($differences['extra_columns'] ?? [] as $diff) {
        $table = $diff['table'];
        if (!isset($tableChanges[$table])) {
            $tableChanges[$table] = ['add' => [], 'alter' => [], 'fks' => [], 'drop_cols' => [], 'indexes' => []];
        }
        $tableChanges[$table]['drop_cols'][] = $diff;
    }
    
    // Group all column alterations together (type, nullable, default)
    // This ensures all changes for a column are processed together
    // First, create a map of all column changes by table.column
    $columnChangeMap = [];
    
    foreach ($differences['type_mismatches'] as $diff) {
        $key = $diff['table'] . '.' . $diff['column'];
        if (!isset($columnChangeMap[$key])) {
            $columnChangeMap[$key] = [
                'table' => $diff['table'],
                'column' => $diff['column'],
                'type_mismatch' => null,
                'nullable_mismatch' => null,
                'default_mismatch' => null,
            ];
        }
        $columnChangeMap[$key]['type_mismatch'] = $diff;
    }
    
    foreach ($differences['nullable_mismatches'] as $diff) {
        $key = $diff['table'] . '.' . $diff['column'];
        if (!isset($columnChangeMap[$key])) {
            $columnChangeMap[$key] = [
                'table' => $diff['table'],
                'column' => $diff['column'],
                'type_mismatch' => null,
                'nullable_mismatch' => null,
                'default_mismatch' => null,
            ];
        }
        $columnChangeMap[$key]['nullable_mismatch'] = $diff;
    }
    
    foreach ($differences['default_mismatches'] ?? [] as $diff) {
        $key = $diff['table'] . '.' . $diff['column'];
        if (!isset($columnChangeMap[$key])) {
            $columnChangeMap[$key] = [
                'table' => $diff['table'],
                'column' => $diff['column'],
                'type_mismatch' => null,
                'nullable_mismatch' => null,
                'default_mismatch' => null,
            ];
        }
        $columnChangeMap[$key]['default_mismatch'] = $diff;
    }
    
    // Now add all grouped changes to tableChanges
    foreach ($columnChangeMap as $change) {
        $table = $change['table'];
        if (!isset($tableChanges[$table])) {
            $tableChanges[$table] = ['add' => [], 'alter' => [], 'fks' => [], 'drop_cols' => [], 'indexes' => []];
        }
        // Add all mismatches for this column as a single entry
        $tableChanges[$table]['alter'][] = $change;
    }
    
    foreach ($differences['missing_foreign_keys'] as $diff) {
        $table = $diff['table'];
        if (!isset($tableChanges[$table])) {
            $tableChanges[$table] = ['add' => [], 'alter' => [], 'fks' => [], 'drop_cols' => [], 'indexes' => []];
        }
        $tableChanges[$table]['fks'][] = $diff;
    }
    
    foreach ($differences['wrong_foreign_keys'] as $diff) {
        $table = $diff['table'];
        if (!isset($tableChanges[$table])) {
            $tableChanges[$table] = ['add' => [], 'alter' => [], 'fks' => [], 'drop_cols' => [], 'indexes' => []];
        }
        $tableChanges[$table]['fks'][] = $diff;
    }
    
    foreach ($differences['missing_indexes'] ?? [] as $diff) {
        $table = $diff['table'];
        if (!isset($tableChanges[$table])) {
            $tableChanges[$table] = ['add' => [], 'alter' => [], 'fks' => [], 'drop_cols' => [], 'indexes' => []];
        }
        $tableChanges[$table]['indexes'][] = ['action' => 'add', 'index' => $diff['index']];
    }
    
    foreach ($differences['extra_indexes'] ?? [] as $diff) {
        $table = $diff['table'];
        if (!isset($tableChanges[$table])) {
            $tableChanges[$table] = ['add' => [], 'alter' => [], 'fks' => [], 'drop_cols' => [], 'indexes' => []];
        }
        $tableChanges[$table]['indexes'][] = ['action' => 'drop', 'index' => $diff['index']];
    }
    
    // Generate table alterations
    foreach ($tableChanges as $table => $changes) {
        $migration .= "        // Alter table: {$table}\n";
        $migration .= "        if (Schema::hasTable('{$table}')) {\n";
        $migration .= "            Schema::table('{$table}', function (Blueprint \$table) {\n";
        
        // Drop extra columns first (before adding/altering)
        // IMPORTANT: Drop FKs on these columns first!
        foreach ($changes['drop_cols'] ?? [] as $change) {
            $colName = $change['column'];
            $migration .= "                // Drop extra column: {$colName}\n";
            $migration .= "                if (Schema::hasColumn('{$table}', '{$colName}')) {\n";
            
            // Check if column has FKs and drop them first
            $fksOnColumn = getFKsOnColumn($devSchema, $table, $colName);
            if (!empty($fksOnColumn)) {
                $migration .= "                    // Drop FKs on this column first\n";
                $migration .= "                    try {\n";
                $migration .= "                        \$fks = DB::select(\n";
                $migration .= "                            \"SELECT DISTINCT kcu.CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE kcu\n";
                $migration .= "                             INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc \n";
                $migration .= "                             ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME\n";
                $migration .= "                             WHERE kcu.TABLE_SCHEMA = DATABASE()\n";
                $migration .= "                             AND kcu.TABLE_NAME = '{$table}'\n";
                $migration .= "                             AND kcu.COLUMN_NAME = '{$colName}'\n";
                $migration .= "                            \"\n";
                $migration .= "                        );\n";
                $migration .= "                        foreach (\$fks as \$fk) {\n";
                $migration .= "                            try {\n";
                $migration .= "                                DB::statement('ALTER TABLE `{$table}` DROP FOREIGN KEY `' . \$fk->CONSTRAINT_NAME . '`');\n";
                $migration .= "                            } catch (\\Throwable \$e) {\n";
                $migration .= "                                // FK might already be dropped; ignore\n";
                $migration .= "                            }\n";
                $migration .= "                        }\n";
                $migration .= "                    } catch (\\Throwable \$e) {\n";
                $migration .= "                        // No FKs to drop; ignore\n";
                $migration .= "                    }\n";
            }
            
            $migration .= "                    try {\n";
            $migration .= "                        \$table->dropColumn('{$colName}');\n";
            $migration .= "                    } catch (\\Throwable \$e) {\n";
            $migration .= "                        // Column might have dependencies; ignore\n";
            $migration .= "                    }\n";
            $migration .= "                }\n";
        }
        
        // Add missing columns
        foreach ($changes['add'] as $change) {
            $col = $change['definition'];
            $migration .= "                if (!Schema::hasColumn('{$table}', '{$change['column']}')) {\n";
            $migration .= generateColumnDef($col, $change['column']);
            $migration .= "                }\n";
        }
        
        // Alter columns using Doctrine DBAL ->change()
        // IMPORTANT: If column is referenced by FKs, drop them first, then recreate after change
        if (!empty($changes['alter'])) {
            // Changes are already grouped by column in the previous step
            // Each entry in 'alter' contains all mismatches for that column
            foreach ($changes['alter'] as $columnChange) {
                $colName = $columnChange['column'];
                
                // Get the master column definition to know what it should be
                $masterCol = null;
                if (isset($masterSchema[$table]['columns'][$colName])) {
                    $masterCol = $masterSchema[$table]['columns'][$colName];
                }
                
                if (!$masterCol) {
                    continue; // Can't change a column we don't have a definition for
                }
                
                // Override master column properties with mismatch values if present
                // This ensures we use the correct values from the comparison
                if (isset($columnChange['nullable_mismatch'])) {
                    $masterCol['nullable'] = $columnChange['nullable_mismatch']['master'];
                }
                if (isset($columnChange['default_mismatch'])) {
                    $masterCol['default'] = $columnChange['default_mismatch']['master'];
                }
                if (isset($columnChange['type_mismatch'])) {
                    // Type is already in masterCol, but we might need to update it
                    $masterCol['type'] = $columnChange['type_mismatch']['master'];
                }
                
                // Check if this column is referenced by FKs in other tables
                // This is critical for PK columns or columns that are FK targets
                $referencingFKs = getFKsReferencingColumn($devSchema, $table, $colName);
                $hasTypeChange = isset($columnChange['type_mismatch']);
                
                // If column is referenced by FKs AND we're changing its type, drop referencing FKs first
                if (!empty($referencingFKs) && $hasTypeChange) {
                    $migration .= "                // Column {$colName} is referenced by FKs - drop them before changing type\n";
                    $migration .= "                try {\n";
                    $migration .= "                    \$referencingFKs = DB::select(\n";
                    $migration .= "                        \"SELECT DISTINCT kcu.CONSTRAINT_NAME, kcu.TABLE_NAME\n";
                    $migration .= "                         FROM information_schema.KEY_COLUMN_USAGE kcu\n";
                    $migration .= "                         WHERE kcu.TABLE_SCHEMA = DATABASE()\n";
                    $migration .= "                         AND kcu.REFERENCED_TABLE_NAME = '{$table}'\n";
                    $migration .= "                         AND kcu.REFERENCED_COLUMN_NAME = '{$colName}'\n";
                    $migration .= "                        \"\n";
                    $migration .= "                    );\n";
                    $migration .= "                    foreach (\$referencingFKs as \$refFK) {\n";
                    $migration .= "                        try {\n";
                    $migration .= "                            DB::statement('ALTER TABLE `' . \$refFK->TABLE_NAME . '` DROP FOREIGN KEY `' . \$refFK->CONSTRAINT_NAME . '`');\n";
                    $migration .= "                        } catch (\\Throwable \$e) {\n";
                    $migration .= "                            // FK might already be dropped; ignore\n";
                    $migration .= "                        }\n";
                    $migration .= "                    }\n";
                    $migration .= "                } catch (\\Throwable \$e) {\n";
                    $migration .= "                    // No referencing FKs to drop; ignore\n";
                    $migration .= "                }\n";
                    
                }
                
                // Generate the change - this will include type, nullable, and default
                // generateColumnChange already includes try-catch
                $migration .= generateColumnChange($masterCol, $colName, $columnChange);
                
                // Recreate FKs that reference this column (if we dropped them)
                if (!empty($referencingFKs) && $hasTypeChange) {
                    $migration .= "                // Recreate FKs that reference this column\n";
                    foreach ($referencingFKs as $refFK) {
                        $fkDetails = $refFK['fk'];
                        $refTable = $refFK['table'];
                        $refCol = $refFK['column'];
                        $onDelete = strtolower($fkDetails['on_delete']);
                        
                        $migration .= "                // Recreate FK: {$refTable}.{$refCol} -> {$table}.{$colName}\n";
                        $migration .= "                try {\n";
                        $migration .= "                    Schema::table('{$refTable}', function (Blueprint \$t) {\n";
                        $migration .= "                        \$t->foreign('{$refCol}')->references('{$colName}')->on('{$table}')->onDelete('{$onDelete}');\n";
                        $migration .= "                    });\n";
                        $migration .= "                } catch (\\Throwable \$e) {\n";
                        $migration .= "                    // FK might not be addable; ignore\n";
                        $migration .= "                }\n";
                    }
                }
            }
        }
        
        // Fix foreign keys
        $fkChanges = [];
        foreach ($changes['fks'] as $change) {
            // Check if this is a "wrong_foreign_keys" entry (has 'master' and 'dev' keys)
            if (isset($change['master']) && isset($change['dev'])) {
                // Wrong foreign key - need to drop and recreate
                $fkChanges[] = [
                    'action' => 'fix',
                    'column' => $change['column'],
                    'master' => $change['master'],
                    'dev' => $change['dev'],
                    'fk' => $change['fk'] ?? null, // FK details from comparison
                ];
            } elseif (isset($change['fk'])) {
                // Missing foreign key - need to add
                $fk = $change['fk'];
                $fkChanges[] = [
                    'action' => 'add',
                    'column' => $fk['column'],
                    'references_table' => $fk['references_table'],
                    'references_column' => $fk['references_column'],
                    'on_delete' => $fk['on_delete'],
                ];
            }
        }
        
        // Generate FK operations
        foreach ($fkChanges as $fkChange) {
            if ($fkChange['action'] === 'add') {
                $migration .= "                // Add missing FK: {$fkChange['column']} -> {$fkChange['references_table']}.{$fkChange['references_column']} ({$fkChange['on_delete']})\n";
                $migration .= "                try {\n";
                // Check if FK already exists
                $fkName = "{$table}_{$fkChange['column']}_foreign";
                $migration .= "                    \$fkExists = DB::selectOne(\n";
                $migration .= "                        \"SELECT COUNT(*) as cnt FROM information_schema.key_column_usage kcu\n";
                $migration .= "                         INNER JOIN information_schema.referential_constraints rc \n";
                $migration .= "                         ON kcu.constraint_name = rc.constraint_name\n";
                $migration .= "                         WHERE kcu.table_schema = DATABASE()\n";
                $migration .= "                         AND kcu.table_name = '{$table}'\n";
                $migration .= "                         AND kcu.column_name = '{$fkChange['column']}'\n";
                $migration .= "                         AND kcu.referenced_table_name = '{$fkChange['references_table']}'\n";
                $migration .= "                        \"\n";
                $migration .= "                    );\n";
                $migration .= "                    if (!\$fkExists || \$fkExists->cnt == 0) {\n";
                // Check for orphaned records before creating FK
                $migration .= "                        // Check for orphaned records\n";
                $migration .= "                        \$orphaned = DB::selectOne(\n";
                $migration .= "                            \"SELECT COUNT(*) as cnt FROM `{$table}` t\n";
                $migration .= "                             LEFT JOIN `{$fkChange['references_table']}` r ON t.`{$fkChange['column']}` = r.`{$fkChange['references_column']}`\n";
                $migration .= "                             WHERE t.`{$fkChange['column']}` IS NOT NULL AND r.`{$fkChange['references_column']}` IS NULL\n";
                $migration .= "                            \"\n";
                $migration .= "                        );\n";
                $migration .= "                        if (!\$orphaned || \$orphaned->cnt == 0) {\n";
                $migration .= "                            \$table->foreign('{$fkChange['column']}')->references('{$fkChange['references_column']}')->on('{$fkChange['references_table']}')->onDelete('" . strtolower($fkChange['on_delete']) . "');\n";
                $migration .= "                        } else {\n";
                $migration .= "                            // Skipping FK creation due to orphaned records: \" . \$orphaned->cnt . \" rows\n";
                $migration .= "                        }\n";
                $migration .= "                    }\n";
                $migration .= "                } catch (\\Throwable \$e) {\n";
                $migration .= "                    // FK might already exist or column type mismatch; ignore\n";
                $migration .= "                }\n";
            } elseif ($fkChange['action'] === 'fix') {
                $migration .= "                // Fix FK: {$fkChange['column']} (current: {$fkChange['dev']}, should be: {$fkChange['master']})\n";
                $migration .= "                try {\n";
                // Drop ALL FKs on this column (there might be multiple)
                $migration .= "                    \$fks = DB::select(\n";
                $migration .= "                        \"SELECT DISTINCT kcu.constraint_name FROM information_schema.key_column_usage kcu\n";
                $migration .= "                         INNER JOIN information_schema.referential_constraints rc \n";
                $migration .= "                         ON kcu.constraint_name = rc.constraint_name\n";
                $migration .= "                         WHERE kcu.table_schema = DATABASE()\n";
                $migration .= "                         AND kcu.table_name = '{$table}'\n";
                $migration .= "                         AND kcu.column_name = '{$fkChange['column']}'\n";
                $migration .= "                        \"\n";
                $migration .= "                    );\n";
                $migration .= "                    foreach (\$fks as \$fk) {\n";
                $migration .= "                        try {\n";
                $migration .= "                            DB::statement('ALTER TABLE `{$table}` DROP FOREIGN KEY `' . \$fk->constraint_name . '`');\n";
                $migration .= "                        } catch (\\Throwable \$e) {\n";
                $migration .= "                            // FK might already be dropped; ignore\n";
                $migration .= "                        }\n";
                $migration .= "                    }\n";
                $migration .= "                } catch (\\Throwable \$e) {\n";
                $migration .= "                    // No FKs to drop; ignore\n";
                $migration .= "                }\n";
                // Note: We need the FK details to recreate it - this will be added in next iteration
                if (isset($fkChange['fk'])) {
                    $fk = $fkChange['fk'];
                    $migration .= "                try {\n";
                    // Check for orphaned records before creating FK
                    $migration .= "                    \$orphaned = DB::selectOne(\n";
                    $migration .= "                        \"SELECT COUNT(*) as cnt FROM `{$table}` t\n";
                    $migration .= "                         LEFT JOIN `{$fk['references_table']}` r ON t.`{$fk['column']}` = r.`{$fk['references_column']}`\n";
                    $migration .= "                         WHERE t.`{$fk['column']}` IS NOT NULL AND r.`{$fk['references_column']}` IS NULL\n";
                    $migration .= "                        \"\n";
                    $migration .= "                    );\n";
                    $migration .= "                    if (!\$orphaned || \$orphaned->cnt == 0) {\n";
                    $migration .= "                        \$table->foreign('{$fk['column']}')->references('{$fk['references_column']}')->on('{$fk['references_table']}')->onDelete('" . strtolower($fk['on_delete']) . "');\n";
                    $migration .= "                    } else {\n";
                    $migration .= "                        // Skipping FK creation due to orphaned records: \" . \$orphaned->cnt . \" rows\n";
                    $migration .= "                    }\n";
                    $migration .= "                } catch (\\Throwable \$e) {\n";
                    $migration .= "                    // FK might not be addable; ignore\n";
                    $migration .= "                }\n";
                } else {
                    // Try to find FK details from master schema
                    if (isset($masterSchema[$table]['foreign_keys'])) {
                        foreach ($masterSchema[$table]['foreign_keys'] as $fk) {
                            if ($fk['column'] === $fkChange['column']) {
                                $migration .= "                try {\n";
                                $migration .= "                    \$table->foreign('{$fk['column']}')->references('{$fk['references_column']}')->on('{$fk['references_table']}')->onDelete('" . strtolower($fk['on_delete']) . "');\n";
                                $migration .= "                } catch (\\Throwable \$e) {\n";
                                $migration .= "                    // FK might not be addable; ignore\n";
                                $migration .= "                }\n";
                                break;
                            }
                        }
                    } else {
                        $migration .= "                // TODO: Recreate FK with correct delete rule (need FK details)\n";
                    }
                }
            }
        }
        
        // Handle indexes
        if (!empty($changes['indexes'])) {
            foreach ($changes['indexes'] as $idxChange) {
                $idx = $idxChange['index'];
                if ($idxChange['action'] === 'drop') {
                    $migration .= "                // Drop extra index: {$idx['name']}\n";
                    $migration .= "                try {\n";
                    // Check if index exists by column name and get its actual name
                    $col = $idx['columns'][0];
                    $migration .= "                    \$indexInfo = DB::selectOne(\n";
                    $migration .= "                        \"SELECT index_name FROM information_schema.statistics \n";
                    $migration .= "                         WHERE table_schema = DATABASE() \n";
                    $migration .= "                         AND table_name = '{$table}' \n";
                    $migration .= "                         AND column_name = '{$col}'\n";
                    $migration .= "                         AND index_name != 'PRIMARY'\n";
                    $migration .= "                        \"\n";
                    $migration .= "                    );\n";
                    $migration .= "                    if (\$indexInfo && \$indexInfo->index_name) {\n";
                    $migration .= "                        // Drop by actual index name\n";
                    if ($idx['unique']) {
                        $migration .= "                        DB::statement(\"ALTER TABLE `{$table}` DROP INDEX `\" . \$indexInfo->index_name . \"`\");\n";
                    } else {
                        $migration .= "                        DB::statement(\"ALTER TABLE `{$table}` DROP INDEX `\" . \$indexInfo->index_name . \"`\");\n";
                    }
                    $migration .= "                    }\n";
                    $migration .= "                } catch (\\Throwable \$e) {\n";
                    $migration .= "                    // Index might not exist or have different name; ignore\n";
                    $migration .= "                }\n";
                } elseif ($idxChange['action'] === 'add') {
                    $migration .= "                // Add missing index: {$idx['name']}\n";
                    $migration .= "                try {\n";
                    if ($idx['unique']) {
                        $cols = "'" . implode("', '", $idx['columns']) . "'";
                        $migration .= "                    \$table->unique([{$cols}], '{$idx['name']}');\n";
                    } else {
                        $cols = "'" . implode("', '", $idx['columns']) . "'";
                        $migration .= "                    \$table->index([{$cols}], '{$idx['name']}');\n";
                    }
                    $migration .= "                } catch (\\Throwable \$e) {\n";
                    $migration .= "                    // Index might already exist; ignore\n";
                    $migration .= "                }\n";
                }
            }
        }
        
        $migration .= "            });\n";
        $migration .= "        }\n\n";
    }
    
    $migration .= "        if (\$driver === 'mysql' || \$driver === 'mariadb') {\n";
    $migration .= "            DB::statement('SET FOREIGN_KEY_CHECKS=1;');\n";
    $migration .= "        }\n";
    $migration .= "    }\n\n";
    $migration .= "    public function down(): void\n";
    $migration .= "    {\n";
    $migration .= "        // Rollback not implemented\n";
    $migration .= "    }\n";
    $migration .= "};\n";
    
    return $migration;
}

function generateColumnDef($col, $colName)
{
    $def = "                    \$table->";
    
    $type = $col['type'];
    if (strpos($type, 'int(10) unsigned') !== false) {
        $def .= "unsignedInteger('{$colName}')";
    } elseif (preg_match('/varchar\((\d+)\)/', $type, $m)) {
        $def .= "string('{$colName}', {$m[1]})";
    } elseif (strpos($type, 'text') !== false) {
        $def .= "text('{$colName}')";
    } elseif (strpos($type, 'timestamp') !== false) {
        $def .= "timestamp('{$colName}')";
    } else {
        $def .= "string('{$colName}')";
    }
    
    if ($col['nullable']) {
        $def .= "->nullable()";
    }
    
    if ($col['default'] !== null) {
        $def .= "->default('{$col['default']}')";
    }
    
    $def .= ";\n";
    return $def;
}

function generateColumnChange($masterCol, $colName, $colChanges)
{
    $change = "                try {\n";
    $change .= "                    \$table->";
    
    // Map MySQL type to Laravel method
    $type = $masterCol['type'];
    $laravelMethod = mapMySQLTypeToLaravelMethod($type);
    
    // Build the method call with parameters
    // Special handling for enum: enum(['val1', 'val2'])
    if (preg_match('/^enum\(\[(.*?)\]\)$/', $laravelMethod, $m)) {
        $change .= "enum('{$colName}', [{$m[1]}])";
    } elseif (preg_match('/^(\w+)\(([^)]*)\)$/', $laravelMethod, $m)) {
        $method = $m[1];
        $params = $m[2];
        if ($params) {
            $change .= "{$method}('{$colName}', {$params})";
        } else {
            $change .= "{$method}('{$colName}')";
        }
    } else {
        $change .= "{$laravelMethod}('{$colName}')";
    }
    
    // Handle nullable - ALWAYS include this based on master definition
    // This ensures columns are set to correct nullable state
    if ($masterCol['nullable']) {
        $change .= "->nullable()";
    } else {
        $change .= "->nullable(false)";
    }
    
    // Handle default - ALWAYS include if master has a default
    // If master has no default (null), check if we need to remove existing default
    if ($masterCol['default'] !== null && $masterCol['default'] !== '') {
        $default = $masterCol['default'];
        // Handle boolean defaults
        if ($default === true || $default === 'true' || $default === 1) {
            $change .= "->default(true)";
        } elseif ($default === false || $default === 'false' || $default === 0) {
            // For numeric 0, check if it's a boolean or integer
            // If it's a boolean column, use false, otherwise use 0
            if (strpos($masterCol['type'], 'tinyint(1)') !== false) {
                $change .= "->default(false)";
            } else {
                $change .= "->default(0)";
            }
        } elseif (is_numeric($default)) {
            $change .= "->default({$default})";
        } else {
            // Escape single quotes in default value
            $defaultEscaped = str_replace("'", "\\'", $default);
            $change .= "->default('{$defaultEscaped}')";
        }
    } elseif (isset($colChanges['default_mismatch']) && 
              $colChanges['default_mismatch']['dev'] !== null && 
              $colChanges['default_mismatch']['dev'] !== '') {
        // Master has no default but Dev has one - we need to remove it
        // Laravel doesn't have a direct way to remove defaults, so we'll set it to NULL
        // This works for nullable columns, but for NOT NULL columns we might need raw SQL
        // For now, we'll just not set a default, which should work in most cases
        // Note: This is a limitation - we can't easily remove defaults from NOT NULL columns
    }
    
    // CRITICAL: Preserve AUTO_INCREMENT if the master column has it
    // This was the root cause of losing AUTO_INCREMENT during sync migrations
    if (isset($masterCol['auto_increment']) && $masterCol['auto_increment']) {
        $change .= "->autoIncrement()";
    }
    
    // Add ->change() to actually modify the column
    $change .= "->change();\n";
    $change .= "                } catch (\\Throwable \$e) {\n";
    $change .= "                    // Column might not exist or can't be modified; ignore\n";
    $change .= "                }\n";
    
    return $change;
}

function mapMySQLTypeToLaravelMethod($mysqlType)
{
    $mysqlType = strtolower($mysqlType);
    
    // Handle unsigned integers
    if (preg_match('/int\(10\)\s*unsigned/', $mysqlType)) {
        return 'unsignedInteger';
    }
    if (preg_match('/int\(11\)/', $mysqlType)) {
        return 'integer';
    }
    if (preg_match('/smallint\((\d+)\)\s*unsigned/', $mysqlType)) {
        return 'unsignedSmallInteger';
    }
    if (preg_match('/tinyint\(3\)\s*unsigned/', $mysqlType)) {
        return 'unsignedTinyInteger';
    }
    if (preg_match('/tinyint\(1\)/', $mysqlType)) {
        return 'boolean';
    }
    
    // Handle varchar
    if (preg_match('/varchar\((\d+)\)/', $mysqlType, $m)) {
        $length = (int)$m[1];
        return "string({$length})";
    }
    
    // Handle text types
    if (strpos($mysqlType, 'longtext') !== false) {
        return 'longText';
    }
    if (strpos($mysqlType, 'text') !== false && strpos($mysqlType, 'longtext') === false) {
        return 'text';
    }
    
    // Handle date/time types
    if (strpos($mysqlType, 'timestamp') !== false) {
        return 'timestamp';
    }
    if (strpos($mysqlType, 'datetime') !== false) {
        return 'datetime';
    }
    if (strpos($mysqlType, 'date') !== false && strpos($mysqlType, 'datetime') === false) {
        return 'date';
    }
    
    // Handle decimal
    if (preg_match('/decimal\((\d+),(\d+)\)/', $mysqlType, $m)) {
        return "decimal({$m[1]}, {$m[2]})";
    }
    
    // Handle enum types
    if (preg_match("/^enum\('([^']+)'(?:,'([^']+)')*\)$/", $mysqlType, $matches)) {
        // Extract all enum values
        preg_match_all("/'([^']+)'/", $mysqlType, $enumValues);
        $values = $enumValues[1];
        $valuesStr = "['" . implode("', '", $values) . "']";
        return "enum({$valuesStr})";
    }
    
    // Default fallback
    return 'string(255)';
}

function displayDifferencesSimple($differences)
{
    $total = 0;
    
    foreach (['missing_tables', 'missing_columns', 'type_mismatches', 'nullable_mismatches', 'missing_foreign_keys', 'wrong_foreign_keys'] as $key) {
        if (!empty($differences[$key])) {
            $count = count($differences[$key]);
            $total += $count;
            echo ucfirst(str_replace('_', ' ', $key)) . ": {$count}\n";
            foreach (array_slice($differences[$key], 0, 10) as $diff) {
                if (isset($diff['table'])) {
                    echo "  - {$diff['table']}." . ($diff['column'] ?? '') . "\n";
                } else {
                    echo "  - {$diff}\n";
                }
            }
            if ($count > 10) {
                echo "  ... and " . ($count - 10) . " more\n";
            }
        }
    }
    
    echo "\nTotal differences: {$total}\n";
}

