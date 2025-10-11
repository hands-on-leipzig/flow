<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\MSeason;
use App\Models\MLevel;
use App\Models\RegionalPartner;
use App\Models\Event;

class SetupTestEnvironment extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:setup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up test environment with fresh data and test events';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('🚀 Setting up test environment...');

        try {
            // Step 1: Create master data (database is already fresh from db:wipe)
            $this->createMasterData();

            // Step 2: Create test data
            $this->createTestData();

            // Step 3: Verification
            $this->verifySetup();

            $this->info('✅ Test environment setup complete!');
            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Setup failed: ' . $e->getMessage());
            return 1;
        }
    }

    private function createMasterData()
    {
        $this->info('Creating master data...');

        // Run the main data seeder (kept up-to-date via GitHub PRs)
        $this->call('db:seed', ['--class' => 'MainDataSeeder']);

        $this->info('Master data created from MainDataSeeder.php');
    }

    private function createTestData()
    {
        $this->info('Creating test data...');

        // Run the test data seeder
        $this->call('db:seed', ['--class' => 'TestDataSeeder']);

        $this->info('Test data created');
    }

    private function verifySetup()
    {
        $this->info('Verifying setup...');

        $this->line('Master tables:');
        $this->line('  Seasons: ' . DB::table('m_season')->count());
        $this->line('  Levels: ' . DB::table('m_level')->count());
        $this->line('  Room Types: ' . DB::table('m_room_type')->count());
        $this->line('  Parameters: ' . DB::table('m_parameter')->count());

        $this->line('Data tables:');
        $this->line('  Regional Partners: ' . DB::table('regional_partner')->count());
        $this->line('  Events: ' . DB::table('event')->count());
        $this->line('  Users: ' . DB::table('user')->count());

        $testEvents = DB::table('event')->whereIn('slug', [
            'rpt-demo-nur-explore',
            'rpt-demo-nur-challenge',
            'rpt-demo'
        ])->get();

        $this->line('Test events created:');
        foreach ($testEvents as $event) {
            $this->line('  ✓ ' . $event->name . ' (ID: ' . $event->id . ')');
        }

        $this->info('Verification complete');
    }
}
