<?php

/**
 * Export Main Data from Dev Database
 * 
 * This script exports all m_ tables from the dev database
 * and generates the MainDataSeeder with actual data.
 * 
 * Usage: php database/scripts/export_main_data.php
 */

use Illuminate\Support\Facades\DB;

// Load Laravel environment
require_once __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

function exportMainData()
{
    echo "🔄 Exporting main data from dev database...\n";
    echo "==========================================\n\n";
    
    // Get dev database credentials from environment
    $devDbName = env('DEV_DB_NAME');
    $devDbUser = env('DEV_DB_USER');
    $devDbPassword = env('DEV_DB_PASSWORD');
    $devDbHost = env('DEV_DB_HOST', 'localhost');
    
    if (!$devDbName || !$devDbUser || !$devDbPassword) {
        echo "❌ Dev database credentials not found in environment\n";
        echo "Please set DEV_DB_NAME, DEV_DB_USER, DEV_DB_PASSWORD in your .env file\n";
        return;
    }
    
    try {
        // Create connection to dev database
        $devPdo = new PDO(
            "mysql:host={$devDbHost};dbname={$devDbName};charset=utf8mb4",
            $devDbUser,
            $devDbPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        echo "✓ Connected to dev database: {$devDbName}@{$devDbHost}\n\n";
        
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
            echo "Exporting {$table}...\n";
            
            // Get data from dev database
            $stmt = $devPdo->prepare("SELECT * FROM {$table}");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($rows) > 0) {
                echo "  ✓ Found " . count($rows) . " records\n";
                
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
                echo "  ⚠ No data found\n";
            }
        }
        
        $seederContent .= "        \n        \$this->command->info('✅ Main data seeded successfully!');\n    }\n}";
        
        // Write the seeder file
        $seederPath = __DIR__ . '/../seeders/MainDataSeeder.php';
        file_put_contents($seederPath, $seederContent);
        
        echo "\n✅ MainDataSeeder generated successfully!\n";
        echo "📁 File saved to: {$seederPath}\n";
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Run the export
exportMainData();
