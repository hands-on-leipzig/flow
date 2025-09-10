<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MainDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Main data seeder is a placeholder.');
        $this->command->info('Please import main table data from dev database manually.');
        $this->command->info('Use the refresh_main_tables.php script to truncate tables first.');
        
        // This seeder is intentionally empty because:
        // 1. Main data should come from dev database
        // 2. We don't want to hardcode main data in the seeder
        // 3. The refresh script handles the truncation
        
        $this->command->info('Main data seeder completed (no data seeded).');
    }
}
