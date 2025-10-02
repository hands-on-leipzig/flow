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
            // Step 1: Purge existing data
            $this->purgeDatabase();

            // Step 2: Create master data
            $this->createMasterData();

            // Step 3: Create test data
            $this->createTestData();

            // Step 4: Verification
            $this->verifySetup();

            $this->info('✅ Test environment setup complete!');
            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Setup failed: ' . $e->getMessage());
            return 1;
        }
    }

    private function purgeDatabase()
    {
        $this->info('Purging existing data...');

        // Disable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        $tables = [
            'activity', 'activity_group', 'event', 'event_logo', 'extra_block', 'logo',
            'plan', 'plan_extra_block', 'plan_param_value', 'regional_partner', 'room',
            'room_type_room', 'table_event', 'team', 'team_plan', 'user', 'user_regional_partner',
            'slideshow', 'slide', 'publication', 's_generator',
            'm_activity_type', 'm_activity_type_detail', 'm_first_program', 'm_insert_point',
            'm_level', 'm_parameter', 'm_role', 'm_room_type', 'm_room_type_group',
            'm_season', 'm_supported_plan', 'm_visibility'
        ];

        foreach ($tables as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->truncate();
                $this->line("  ✓ Cleared: {$table}");
            }
        }

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->info('Database purge completed');
    }

    private function createMasterData()
    {
        $this->info('Creating master data...');

        // Run the main data seeder
        $this->call('db:seed', ['--class' => 'MainDataSeeder']);

        $this->info('Master data created');
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
