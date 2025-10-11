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
        $seederContent = "<?php\n\nnamespace Database\\Seeders;\n\nuse Illuminate\\Database\\Seeder;\nuse Illuminate\\Support\\Facades\\DB;\n\nclass MainDataSeeder extends Seeder\n{\n    /**\n     * Run the database seeds.\n     */\n    public function run(): void\n    {\n        \$this->command->info('🌱 Seeding main data...');\n        \n";
        
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
            
            $seederContent .= "        ];\n        \n        foreach (\$data as \$item) {\n";
            
            // Determine unique key for updateOrInsert
            if (!empty($tableData)) {
                $firstRecord = $tableData[0];
                if (isset($firstRecord['name'])) {
                    $seederContent .= "            DB::table('{$table}')->updateOrInsert(\n                ['name' => \$item['name']],\n                \$item\n            );\n";
                } elseif (isset($firstRecord['id'])) {
                    $seederContent .= "            DB::table('{$table}')->updateOrInsert(\n                ['id' => \$item['id']],\n                \$item\n            );\n";
                } else {
                    $seederContent .= "            DB::table('{$table}')->insert(\$item);\n";
                }
            }
            
            $seederContent .= "        }\n        \n        \$this->command->line('    ✓ Seeded ' . count(\$data) . ' {$displayName}');\n    }\n";
        }
        
        $seederContent .= "}";
        
        return $seederContent;
    }
}
