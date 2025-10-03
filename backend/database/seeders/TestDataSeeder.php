<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\RegionalPartner;
use App\Models\Event;

class TestDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🧪 Seeding test data...');
        
        $this->seedRegionalPartners();
        $this->seedTestEvents();
        
        $this->command->info('✅ Test data seeded successfully!');
    }
    
    private function seedRegionalPartners()
    {
        $this->command->info('  Seeding regional partners...');
        
        $regionalPartners = [
            [
                'name' => 'Test Regional Partner A',
                'region' => 'Test Region A',
                'dolibarr_id' => 2001
            ],
            [
                'name' => 'Test Regional Partner B',
                'region' => 'Test Region B',
                'dolibarr_id' => 2002
            ],
        ];
        
        foreach ($regionalPartners as $partner) {
            RegionalPartner::updateOrCreate(
                ['dolibarr_id' => $partner['dolibarr_id']],
                $partner
            );
        }
        
        $this->command->line('    ✓ Seeded ' . count($regionalPartners) . ' regional partners');
    }
    
    private function seedTestEvents()
    {
        $this->command->info('  Seeding test events...');
        
        // Get the first season and level
        $season = DB::table('m_season')->first();
        $level = DB::table('m_level')->first();
        
        if (!$season || !$level) {
            $this->command->error('    ❌ No season or level found. Please run MasterDataSeeder first.');
            return;
        }
        
        // Get regional partners
        $rp1 = RegionalPartner::where('dolibarr_id', 2001)->first();
        $rp2 = RegionalPartner::where('dolibarr_id', 2002)->first();
        
        if (!$rp1 || !$rp2) {
            $this->command->error('    ❌ Regional partners not found. Please run TestDataSeeder first.');
            return;
        }
        
        $events = [
            [
                'name' => 'RPT Demo - Nur Explore',
                'regional_partner' => $rp1->id,
                'slug' => 'rpt-demo-nur-explore',
                'event_explore' => 1001,
                'event_challenge' => null,
                'days' => 30
            ],
            [
                'name' => 'RPT Demo - Nur Challenge',
                'regional_partner' => $rp1->id,
                'slug' => 'rpt-demo-nur-challenge',
                'event_explore' => null,
                'event_challenge' => 1002,
                'days' => 45
            ],
            [
                'name' => 'RPT Demo',
                'regional_partner' => $rp2->id,
                'slug' => 'rpt-demo',
                'event_explore' => 1003,
                'event_challenge' => 1004,
                'days' => 60
            ]
        ];
        
        foreach ($events as $eventData) {
            Event::updateOrCreate(
                ['slug' => $eventData['slug']],
                array_merge($eventData, [
                    'season' => $season->id,
                    'level' => $level->id,
                    'date' => now()->addDays($eventData['days']),
                    'days' => 1
                ])
            );
        }
        
        $this->command->line('    ✓ Seeded ' . count($events) . ' test events');
    }
}
