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
        
        $seederContent = "<?php\n\nnamespace Database\\Seeders;\n\nuse Illuminate\\Database\\Seeder;\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Schema;\n\nclass MainDataSeeder extends Seeder\n{\n    /**\n     * Run the database seeds.\n     */\n    public function run(): void\n    {\n        \$this->command->info('🌱 Seeding main data...');\n        \n";
        
        foreach ($tables as $table) {
            $methodName = 'seed' . str_replace('m_', '', $table);
            $methodName = str_replace('_', '', ucwords($methodName, '_'));
            
            $seederContent .= "        \$this->{$methodName}();\n";
        }
        
        $seederContent .= "        \n        \$this->command->info('✅ Main data seeded successfully!');\n    }\n";
        
        // Generate methods for each table
        foreach ($tables as $table) {
            $methodName = 'seed' . str_replace('m_', '', $table);
            $methodName = str_replace('_', '', ucwords($methodName, '_'));
            
            $tableData = $data[$table] ?? [];
            $displayName = str_replace('m_', '', $table);
            
            $seederContent .= "    \n    private function {$methodName}()\n    {\n        \$this->command->info('  Seeding {$displayName}...');\n        \n        \$data = [\n";
            
            foreach ($tableData as $record) {
                $seederContent .= "            " . var_export($record, true) . ",\n";
            }
            
            $seederContent .= "        ];\n        \n        // Get actual table columns to filter out non-existent columns\n        \$tableColumns = Schema::getColumnListing('{$table}');\n        \n        foreach (\$data as \$item) {\n            // Filter item to only include columns that exist in the table\n            \$filteredItem = array_intersect_key(\$item, array_flip(\$tableColumns));\n            \n            // Determine unique key for updateOrInsert\n            // Prioritize 'id' over 'name' to preserve IDs for foreign key relationships\n";
            
            if (!empty($tableData)) {
                $firstRecord = $tableData[0];
                if (isset($firstRecord['id'])) {
                    $seederContent .= "            DB::table('{$table}')->updateOrInsert(\n                ['id' => \$filteredItem['id']],\n                \$filteredItem\n            );\n";
                } elseif (isset($firstRecord['name'])) {
                    $seederContent .= "            DB::table('{$table}')->updateOrInsert(\n                ['name' => \$filteredItem['name']],\n                \$filteredItem\n            );\n";
                } else {
                    $seederContent .= "            DB::table('{$table}')->insert(\$filteredItem);\n";
                }
            }
            
            $seederContent .= "        }\n        \n        \$this->command->line('    ✓ Seeded ' . count(\$data) . ' {$displayName}');\n    }\n";
        }
        
        $seederContent .= "}";
        
        return $seederContent;
    }
}
