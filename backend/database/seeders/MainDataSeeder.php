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
        // Use base_path to ensure we're reading from backend/database/exports, not root/database/exports
        $exportFilePath = base_path('database/exports/main-tables-latest.json');
        
        // Better error reporting
        if (!file_exists($exportFilePath)) {
            $checkPath = __DIR__ . '/../exports/main-tables-latest.json';
            
            $this->command->error("Export file not found at: {$exportFilePath}");
            $this->command->error("Checked relative path: {$checkPath} (exists: " . (file_exists($checkPath) ? 'yes' : 'no') . ")");
            
            // List directory contents for debugging
            $exportsDir = base_path('database/exports');
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
        
        // Disable foreign key checks during seeding to handle data inconsistencies
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        
        $errors = [];
        try {
            // Seed all tables dynamically from JSON metadata
            foreach ($tables as $table) {
                try {
                    $tableData = $exportData[$table] ?? [];
                    $this->seedTable($table, $tableData);
                } catch (\Exception $e) {
                    $errors[] = "Error seeding {$table}: " . $e->getMessage();
                    $this->command->error("  ❌ Failed to seed {$table}: " . $e->getMessage());
                }
            }
        } finally {
            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
        
        // Verify that tables were populated (dynamic verification)
        $this->command->info('Verifying seeded data...');
        $verificationErrors = [];
        
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                $verificationErrors[] = "Table {$table} does not exist";
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
        
        if (empty($data)) {
            $this->command->warn("    ⚠️  No data found for {$table}");
            return;
        }
        
        // Get actual table columns to filter out non-existent columns
        $tableColumns = Schema::getColumnListing($table);
        
        // Determine unique key for updateOrInsert
        // Check first record to determine available keys
        $firstRecord = reset($data);
        $hasId = isset($firstRecord['id']);
        $hasName = isset($firstRecord['name']);
        
        foreach ($data as $item) {
            // Filter item to only include columns that exist in the table
            $filteredItem = array_intersect_key($item, array_flip($tableColumns));
            
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
}
