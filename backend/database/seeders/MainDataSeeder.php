<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MainDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🌱 Seeding main data...');
        
        // Load data from JSON export file (in repo at backend/database/exports/)
        // Use database_path to ensure we're always reading from backend/database/exports
        $exportFilePath = database_path('exports/main-tables-latest.json');
        
        // Better error reporting
        if (!file_exists($exportFilePath)) {
            $checkPath = __DIR__ . '/../exports/main-tables-latest.json';
            
            $this->command->error("Export file not found at: {$exportFilePath}");
            $this->command->error("Checked relative path: {$checkPath} (exists: " . (file_exists($checkPath) ? 'yes' : 'no') . ")");
            
            // List directory contents for debugging
            $exportsDir = database_path('exports');
            if (is_dir($exportsDir)) {
                $files = scandir($exportsDir);
                $this->command->error("Files in database/exports/: " . implode(', ', array_filter($files, fn($f) => $f !== '.' && $f !== '..')));
            } else {
                $this->command->error("Directory database/exports/ does not exist");
            }
            
            throw new \Exception("Export file not found: {$exportFilePath}. Please ensure main-tables-latest.json exists in backend/database/exports/.");
        }
        
        $content = file_get_contents($exportFilePath);
        if ($content === false) {
            throw new \Exception("Failed to read export file: {$exportFilePath}");
        }
        
        $exportData = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON in export file: " . json_last_error_msg());
        }
        
        if (!$exportData || !isset($exportData['_metadata'])) {
            throw new \Exception('Invalid export file format - missing _metadata. File size: ' . filesize($exportFilePath) . ' bytes');
        }
        
        // Get table list from metadata (dynamic - tables can be added/removed in dev)
        $tables = $exportData['_metadata']['tables'] ?? [];
        if (empty($tables)) {
            throw new \Exception('No tables found in export metadata');
        }
        
        $this->command->info("Found " . count($tables) . " tables in export metadata:");
        foreach ($tables as $table) {
            $dataCount = count($exportData[$table] ?? []);
            $this->command->line("  - {$table}: {$dataCount} records");
        }
        
        // Disable foreign key checks during seeding to handle data inconsistencies
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF;');
        }
        
        $errors = [];
        $skippedTables = [];
        $successfulTables = [];
        try {
            // Seed all tables dynamically from JSON metadata
            foreach ($tables as $table) {
                try {
                    $tableData = $exportData[$table] ?? [];
                    
                    // Check if table exists before attempting to seed
                    if (!Schema::hasTable($table)) {
                        // Table doesn't exist - try to create it dynamically from JSON data
                        $this->command->warn("  ⚠️  Table {$table} does not exist - attempting to create from JSON structure...");
                        try {
                            $this->createTableFromData($table, $tableData);
                            $this->command->info("  ✓ Created table {$table} from JSON structure");
                        } catch (\Exception $e) {
                            $skippedTables[] = $table;
                            $this->command->error("  ❌ Failed to create table {$table}: " . $e->getMessage());
                            $this->command->warn("  ⚠️  Skipping {$table} - could not create table");
                            continue;
                        }
                    }
                    
                    $this->seedTable($table, $tableData);
                    $successfulTables[] = $table;
                } catch (\Exception $e) {
                    $errors[] = "Error seeding {$table}: " . $e->getMessage();
                    $this->command->error("  ❌ Failed to seed {$table}: " . $e->getMessage());
                }
            }
        } finally {
            // Re-enable foreign key checks
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON;');
            }
        }
        
        // Summary of seeding results
        $this->command->info('');
        $this->command->info('📊 Seeding Summary:');
        $this->command->info("  ✓ Successfully seeded: " . count($successfulTables) . " table(s)");
        if (!empty($skippedTables)) {
            $this->command->warn("  ⚠️  Skipped (table doesn't exist): " . count($skippedTables) . " table(s)");
            foreach ($skippedTables as $table) {
                $this->command->warn("    - {$table}");
            }
        }
        if (!empty($errors)) {
            $this->command->error("  ❌ Failed: " . count($errors) . " table(s)");
        }
        $this->command->info('');
        
        // Verify that tables were populated (dynamic verification)
        $this->command->info('Verifying seeded data...');
        $verificationErrors = [];
        $verificationWarnings = [];
        
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                // Table doesn't exist - this is a warning, not an error (migration may not have run yet)
                $verificationWarnings[] = "Table {$table} does not exist (migration may not have run yet)";
                continue;
            }
            
            $count = DB::table($table)->count();
            $expectedCount = count($exportData[$table] ?? []);
            
            if ($expectedCount > 0 && $count < $expectedCount) {
                $verificationErrors[] = "Table {$table} has only {$count} rows, expected at least {$expectedCount}";
            } else {
                $this->command->line("  ✓ {$table}: {$count} rows");
            }
        }
        
        // Show warnings (non-fatal)
        if (!empty($verificationWarnings)) {
            $this->command->warn('Verification warnings (non-fatal):');
            foreach ($verificationWarnings as $warning) {
                $this->command->warn("  - {$warning}");
            }
            $this->command->warn('');
            $this->command->warn('💡 Note: If migrations run later and create these tables,');
            $this->command->warn('   re-run this seeder to populate them: php artisan db:seed --class=MainDataSeeder --force');
        }
        
        // Show errors (fatal)
        if (!empty($verificationErrors)) {
            $this->command->error('Verification failed:');
            foreach ($verificationErrors as $error) {
                $this->command->error("  - {$error}");
            }
            $errors = array_merge($errors, $verificationErrors);
        }
        
        if (empty($errors)) {
            $this->command->info('✅ Main data seeded successfully!');
        } else {
            $this->command->warn('⚠️  Seeding completed with errors:');
            foreach ($errors as $error) {
                $this->command->error("  - {$error}");
            }
            throw new \Exception('Seeding failed with ' . count($errors) . ' error(s)');
        }
    }
    
    /**
     * Generic method to seed any table dynamically
     */
    private function seedTable(string $table, array $data): void
    {
        $displayName = str_replace('m_', '', $table);
        $this->command->info("  Seeding {$displayName}...");
        
        // Check if table exists before trying to seed
        if (!Schema::hasTable($table)) {
            $this->command->warn("    ⚠️  Table {$table} does not exist - skipping (migration may not have run yet)");
            return;
        }
        
        if (empty($data)) {
            $this->command->warn("    ⚠️  No data found for {$table}");
            return;
        }
        
        // Get actual table columns to filter out non-existent columns
        $tableColumns = Schema::getColumnListing($table);
        
        // Get column information to check for NOT NULL constraints
        $columnInfo = [];
        try {
            $columns = DB::select("SHOW COLUMNS FROM `{$table}`");
            foreach ($columns as $column) {
                $columnInfo[$column->Field] = [
                    'nullable' => $column->Null === 'YES',
                    'default' => $column->Default,
                    'type' => $column->Type,
                ];
            }
        } catch (\Throwable $e) {
            // If we can't get column info, proceed without it
        }
        
        // Determine unique key for updateOrInsert
        // Check first record to determine available keys
        $firstRecord = reset($data);
        $hasId = isset($firstRecord['id']);
        $hasName = isset($firstRecord['name']);
        
        foreach ($data as $item) {
            // Filter item to only include columns that exist in the table
            $filteredItem = array_intersect_key($item, array_flip($tableColumns));
            
            // Handle NOT NULL columns with null values
            foreach ($filteredItem as $key => $value) {
                if ($value === null && isset($columnInfo[$key])) {
                    $info = $columnInfo[$key];
                    if (!$info['nullable']) {
                        // Column is NOT NULL but value is null
                        // Remove it to let database use default, or provide sensible default
                        if ($info['default'] !== null) {
                            // Database has a default, remove the null value
                            unset($filteredItem[$key]);
                        } else {
                            // No default, provide sensible default based on type
                            $default = $this->getSensibleDefault($info['type'], $key);
                            if ($default !== null) {
                                $filteredItem[$key] = $default;
                            } else {
                                // Can't provide default, remove it (will fail if truly required)
                                unset($filteredItem[$key]);
                            }
                        }
                    }
                }
            }
            
            // Use appropriate unique key for updateOrInsert
            // Prioritize 'id' over 'name' to preserve IDs for foreign key relationships
            if ($hasId && isset($filteredItem['id'])) {
                DB::table($table)->updateOrInsert(
                    ['id' => $filteredItem['id']],
                    $filteredItem
                );
            } elseif ($hasName && isset($filteredItem['name'])) {
                DB::table($table)->updateOrInsert(
                    ['name' => $filteredItem['name']],
                    $filteredItem
                );
            } else {
                DB::table($table)->insert($filteredItem);
            }
        }
        
        $this->command->line("    ✓ Seeded " . count($data) . " {$displayName}");
    }
    
    /**
     * Get a sensible default value for a NOT NULL column based on its type
     */
    private function getSensibleDefault(string $type, string $columnName): mixed
    {
        // String types - default to empty string
        if (stripos($type, 'varchar') !== false || 
            stripos($type, 'char') !== false || 
            stripos($type, 'text') !== false) {
            // Special case: overview_plan_column should be empty string (per migration)
            if ($columnName === 'overview_plan_column') {
                return '';
            }
            return '';
        }
        
        // Integer types - default to 0
        if (stripos($type, 'int') !== false || 
            stripos($type, 'tinyint') !== false || 
            stripos($type, 'smallint') !== false || 
            stripos($type, 'mediumint') !== false || 
            stripos($type, 'bigint') !== false) {
            return 0;
        }
        
        // Decimal/float types - default to 0.0
        if (stripos($type, 'decimal') !== false || 
            stripos($type, 'float') !== false || 
            stripos($type, 'double') !== false) {
            return 0.0;
        }
        
        // Boolean types - default to false
        if (stripos($type, 'bool') !== false || 
            stripos($type, 'tinyint(1)') !== false) {
            return false;
        }
        
        // Date/time types - return null (let database handle)
        if (stripos($type, 'date') !== false || 
            stripos($type, 'time') !== false || 
            stripos($type, 'timestamp') !== false) {
            return null;
        }
        
        // JSON types - default to empty array
        if (stripos($type, 'json') !== false) {
            return '[]';
        }
        
        // Unknown type - return null
        return null;
    }
    
    /**
     * Create a table dynamically from JSON data structure
     * This makes the JSON file the source of truth - if a table is in JSON, it should exist
     */
    private function createTableFromData(string $table, array $data): void
    {
        if (empty($data)) {
            throw new \Exception("Cannot create table {$table} - no data provided to infer schema");
        }
        
        // Get the first record to infer column structure
        $firstRecord = reset($data);
        if (!is_array($firstRecord)) {
            throw new \Exception("Cannot create table {$table} - invalid data structure");
        }
        
        // Check if timestamps are needed
        $hasTimestamps = false;
        foreach ($data as $record) {
            if (isset($record['created_at']) || isset($record['updated_at'])) {
                $hasTimestamps = true;
                break;
            }
        }
        
        Schema::create($table, function ($tableBlueprint) use ($firstRecord, $data, $hasTimestamps) {
            // Always add id column first (primary key)
            $tableBlueprint->id();
            
            // Infer column types from the first record
            foreach ($firstRecord as $columnName => $value) {
                // Skip id as we already added it
                if ($columnName === 'id') {
                    continue;
                }
                
                // Infer column type from value
                if (is_int($value)) {
                    // Check if it's a small integer (like sequence, year)
                    if ($value >= -32768 && $value <= 32767 && (
                        str_contains($columnName, 'sequence') || 
                        str_contains($columnName, 'year') ||
                        str_contains($columnName, 'level') ||
                        str_contains($columnName, 'rounds') ||
                        str_contains($columnName, 'count')
                    )) {
                        $tableBlueprint->smallInteger($columnName)->nullable();
                    } elseif ($value >= -2147483648 && $value <= 2147483647) {
                        $tableBlueprint->integer($columnName)->nullable();
                    } else {
                        $tableBlueprint->bigInteger($columnName)->nullable();
                    }
                } elseif (is_bool($value) || $value === 0 || $value === 1) {
                    $tableBlueprint->boolean($columnName)->nullable();
                } elseif (is_float($value)) {
                    $tableBlueprint->decimal($columnName, 10, 2)->nullable();
                } elseif (is_string($value)) {
                    // Estimate string length from all records to get max length
                    $maxLength = strlen($value);
                    foreach ($data as $record) {
                        if (isset($record[$columnName]) && is_string($record[$columnName])) {
                            $maxLength = max($maxLength, strlen($record[$columnName]));
                        }
                    }
                    
                    if ($maxLength <= 50) {
                        $tableBlueprint->string($columnName, 50)->nullable();
                    } elseif ($maxLength <= 100) {
                        $tableBlueprint->string($columnName, 100)->nullable();
                    } elseif ($maxLength <= 255) {
                        $tableBlueprint->string($columnName, 255)->nullable();
                    } elseif ($maxLength <= 500) {
                        $tableBlueprint->string($columnName, 500)->nullable();
                    } else {
                        $tableBlueprint->text($columnName)->nullable();
                    }
                } else {
                    // Default to text for unknown types
                    $tableBlueprint->text($columnName)->nullable();
                }
            }
            
            // Add timestamps if needed
            if ($hasTimestamps) {
                $tableBlueprint->timestamps();
            }
        });
        
        $columnCount = count($firstRecord) + ($hasTimestamps ? 2 : 0);
        $this->command->info("    ✓ Created table {$table} with {$columnCount} columns");
    }
}
