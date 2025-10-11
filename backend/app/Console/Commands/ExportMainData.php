<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExportMainData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'main-data:export';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export main data from current database and generate MainDataSeeder';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('🔄 Exporting main data from current database...');
        
        // Define all m_ tables
        $masterTables = [
            'm_season',
            'm_level', 
            'm_room_type',
            'm_room_type_group',
            'm_parameter',
            'm_activity_type',
            'm_activity_type_detail',
            'm_first_program',
            'm_insert_point',
            'm_role',
            'm_visibility',
            'm_supported_plan'
        ];
        
        $seederContent = "<?php\n\nnamespace Database\\Seeders;\n\nuse Illuminate\\Database\\Seeder;\nuse Illuminate\\Support\\Facades\\DB;\n\nclass MainDataSeeder extends Seeder\n{\n    /**\n     * Run the database seeds.\n     */\n    public function run(): void\n    {\n        \$this->command->info('🌱 Seeding main data...');\n        \n";
        
        foreach ($masterTables as $table) {
            $this->info("Exporting {$table}...");
            
            // Get data from current database
            $rows = DB::table($table)->get()->toArray();
            $rows = array_map(function($row) {
                return (array) $row;
            }, $rows);
            
            if (count($rows) > 0) {
                $this->line("  ✓ Found " . count($rows) . " records");
                
                // Generate seeder method
                $methodName = 'seed' . str_replace('m_', '', $table);
                $methodName = str_replace('_', '', ucwords($methodName, '_'));
                
                $seederContent .= "        \$this->{$methodName}();\n";
                
                // Generate method content
                $methodContent = "    \n    private function {$methodName}()\n    {\n        \$this->command->info('  Seeding " . str_replace('m_', '', $table) . "...');\n        \n        \$data = [\n";
                
                foreach ($rows as $row) {
                    $methodContent .= "            " . var_export($row, true) . ",\n";
                }
                
                $methodContent .= "        ];\n        \n        foreach (\$data as \$item) {\n";
                
                // Determine unique key for updateOrInsert
                if (isset($row['name'])) {
                    $methodContent .= "            DB::table('{$table}')->updateOrInsert(\n                ['name' => \$item['name']],\n                \$item\n            );\n";
                } elseif (isset($row['id'])) {
                    $methodContent .= "            DB::table('{$table}')->updateOrInsert(\n                ['id' => \$item['id']],\n                \$item\n            );\n";
                } else {
                    $methodContent .= "            DB::table('{$table}')->insert(\$item);\n";
                }
                
                $methodContent .= "        }\n        \n        \$this->command->line('    ✓ Seeded ' . count(\$data) . ' " . str_replace('m_', '', $table) . "');\n    }\n";
                
                $seederContent .= $methodContent;
            } else {
                $this->warn("  ⚠ No data found");
            }
        }
        
        $seederContent .= "        \n        \$this->command->info('✅ Main data seeded successfully!');\n    }\n}";
        
        // Write the seeder file
        $seederPath = database_path('seeders/MainDataSeeder.php');
        file_put_contents($seederPath, $seederContent);
        
        $this->info('✅ MainDataSeeder generated successfully!');
        $this->line("📁 File saved to: {$seederPath}");
        
        $this->info('✅ Main data export completed!');
        return 0;
    }
}