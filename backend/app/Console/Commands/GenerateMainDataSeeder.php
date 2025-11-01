<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateMainDataSeeder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'main-data:generate-seeder {--file=main-tables-latest.json}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate MainDataSeeder from exported main tables data';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $filename = $this->option('file');
        
        $this->info("🔄 Generating MainDataSeeder from {$filename}...");
        
        // Check if export file exists
        if (!Storage::exists("exports/{$filename}")) {
            $this->error("❌ Export file not found: exports/{$filename}");
            $this->error("Please export the main tables data first using the admin interface.");
            return 1;
        }
        
        try {
            // Load export data
            $content = Storage::get("exports/{$filename}");
            $data = json_decode($content, true);
            
            if (!$data || !isset($data['_metadata'])) {
                $this->error("❌ Invalid export file format");
                return 1;
            }
            
            $tables = $data['_metadata']['tables'] ?? [];
            $this->info("✓ Found data for " . count($tables) . " tables");
            
            // Generate seeder content
            $seederContent = $this->generateSeederContent($tables, $data);
            
            // Write seeder file
            $seederPath = database_path('seeders/MainDataSeeder.php');
            file_put_contents($seederPath, $seederContent);
            
            $this->info("✅ MainDataSeeder generated successfully!");
            $this->line("📁 File saved to: {$seederPath}");
            
            // Show summary
            $totalRecords = 0;
            foreach ($tables as $table) {
                $count = count($data[$table] ?? []);
                $totalRecords += $count;
                $this->line("  ✓ {$table}: {$count} records");
            }
            
            $this->info("📊 Total records: {$totalRecords}");
            
        } catch (\Exception $e) {
            $this->error("❌ Error: " . $e->getMessage());
            return 1;
        }
        
        return 0;
    }
    
    private function generateSeederContent(array $tables, array $data): string
    {
        // Define dependency order for master tables to ensure foreign keys are satisfied
        $dependencyOrder = [
            'm_season' => 1,
            'm_level' => 2,
            'm_first_program' => 3,
            'm_room_type_group' => 4,
            'm_room_type' => 5,
            'm_parameter' => 6,
            'm_activity_type' => 7,
            'm_activity_type_detail' => 8,
            'm_insert_point' => 9,
            'm_role' => 10,
            'm_visibility' => 11,
            'm_supported_plan' => 12,
        ];
        
        // Sort tables according to dependency order
        usort($tables, function($a, $b) use ($dependencyOrder) {
            $orderA = $dependencyOrder[$a] ?? 999;
            $orderB = $dependencyOrder[$b] ?? 999;
            return $orderA <=> $orderB;
        });
        
        $seederContent = "<?php\n\nnamespace Database\\Seeders;\n\nuse Illuminate\\Database\\Seeder;\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Schema;\n\nclass MainDataSeeder extends Seeder\n{\n    /**\n     * Run the database seeds.\n     */\n    public function run(): void\n    {\n        \$this->command->info('🌱 Seeding main data...');\n        \n        // Load data from JSON export file (in repo at database/exports/)\n        \$exportFilePath = database_path('exports/main-tables-latest.json');\n        if (!file_exists(\$exportFilePath)) {\n            throw new \\Exception(\"Export file not found: {\$exportFilePath}. Please ensure main-tables-latest.json exists in database/exports/.\");\n        }\n        \n        \$content = file_get_contents(\$exportFilePath);\n        \$exportData = json_decode(\$content, true);\n        \n        if (!\$exportData || !isset(\$exportData['_metadata'])) {\n            throw new \\Exception('Invalid export file format');\n        }\n        \n        // Get table list from metadata (dynamic - tables can be added/removed in dev)\n        \$tables = \$exportData['_metadata']['tables'] ?? [];\n        if (empty(\$tables)) {\n            throw new \\Exception('No tables found in export metadata');\n        }\n        \n        // Disable foreign key checks during seeding to handle data inconsistencies\n        DB::statement('SET FOREIGN_KEY_CHECKS=0;');\n        \n        \$errors = [];\n        try {\n            // Seed all tables dynamically from JSON metadata\n            foreach (\$tables as \$table) {\n                try {\n                    \$tableData = \$exportData[\$table] ?? [];\n                    \$this->seedTable(\$table, \$tableData);\n                } catch (\\Exception \$e) {\n                    \$errors[] = \"Error seeding {\$table}: \" . \$e->getMessage();\n                    \$this->command->error(\"  ❌ Failed to seed {\$table}: \" . \$e->getMessage());\n                }\n            }\n        } finally {\n            // Re-enable foreign key checks\n            DB::statement('SET FOREIGN_KEY_CHECKS=1;');\n        }\n        \n        // Verify that tables were populated (dynamic verification)\n        \$this->command->info('Verifying seeded data...');\n        \$verificationErrors = [];\n        \n        foreach (\$tables as \$table) {\n            if (!Schema::hasTable(\$table)) {\n                \$verificationErrors[] = \"Table {\$table} does not exist\";\n                continue;\n            }\n            \n            \$count = DB::table(\$table)->count();\n            \$expectedCount = count(\$exportData[\$table] ?? []);\n            \n            if (\$expectedCount > 0 && \$count < \$expectedCount) {\n                \$verificationErrors[] = \"Table {\$table} has only {\$count} rows, expected at least {\$expectedCount}\";\n            } else {\n                \$this->command->line(\"  ✓ {\$table}: {\$count} rows\");\n            }\n        }\n        \n        if (!empty(\$verificationErrors)) {\n            \$this->command->error('Verification failed:');\n            foreach (\$verificationErrors as \$error) {\n                \$this->command->error(\"  - {\$error}\");\n            }\n            \$errors = array_merge(\$errors, \$verificationErrors);\n        }\n        \n        if (empty(\$errors)) {\n            \$this->command->info('✅ Main data seeded successfully!');\n        } else {\n            \$this->command->warn('⚠️  Seeding completed with errors:');\n            foreach (\$errors as \$error) {\n                \$this->command->error(\"  - {\$error}\");\n            }\n            throw new \\Exception('Seeding failed with ' . count(\$errors) . ' error(s)');\n        }\n    }\n    \n    /**\n     * Generic method to seed any table dynamically\n     */\n    private function seedTable(string \$table, array \$data): void\n    {\n        \$displayName = str_replace('m_', '', \$table);\n        \$this->command->info(\"  Seeding {\$displayName}...\");\n        \n        if (empty(\$data)) {\n            \$this->command->warn(\"    ⚠️  No data found for {\$table}\");\n            return;\n        }\n        \n        // Get actual table columns to filter out non-existent columns\n        \$tableColumns = Schema::getColumnListing(\$table);\n        \n        // Determine unique key for updateOrInsert\n        // Check first record to determine available keys\n        \$firstRecord = reset(\$data);\n        \$hasId = isset(\$firstRecord['id']);\n        \$hasName = isset(\$firstRecord['name']);\n        \n        foreach (\$data as \$item) {\n            // Filter item to only include columns that exist in the table\n            \$filteredItem = array_intersect_key(\$item, array_flip(\$tableColumns));\n            \n            // Use appropriate unique key for updateOrInsert\n            // Prioritize 'id' over 'name' to preserve IDs for foreign key relationships\n            if (\$hasId && isset(\$filteredItem['id'])) {\n                DB::table(\$table)->updateOrInsert(\n                    ['id' => \$filteredItem['id']],\n                    \$filteredItem\n                );\n            } elseif (\$hasName && isset(\$filteredItem['name'])) {\n                DB::table(\$table)->updateOrInsert(\n                    ['name' => \$filteredItem['name']],\n                    \$filteredItem\n                );\n            } else {\n                DB::table(\$table)->insert(\$filteredItem);\n            }\n        }\n        \n        \$this->command->line(\"    ✓ Seeded \" . count(\$data) . \" {\$displayName}\");\n    }\n}\n";
        
        return $seederContent;
    }
}
