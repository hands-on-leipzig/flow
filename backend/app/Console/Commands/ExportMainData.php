<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

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
    protected $description = 'Export main data from dev database and generate MainDataSeeder';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('🔄 Exporting main data from dev database...');
        
        // Run the export script
        include database_path('scripts/export_main_data.php');
        
        $this->info('✅ Main data export completed!');
        return 0;
    }
}
