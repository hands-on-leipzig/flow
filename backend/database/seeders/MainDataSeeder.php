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
        $this->command->info('🌱 Seeding main data...');
        
        $this->seedSeasons();
        $this->seedLevels();
        $this->seedRoomTypes();
        $this->seedRoomTypeGroups();
        $this->seedParameters();
        $this->seedActivityTypes();
        $this->seedActivityTypeDetails();
        $this->seedFirstPrograms();
        $this->seedInsertPoints();
        $this->seedRoles();
        $this->seedVisibility();
        $this->seedSupportedPlans();
        
        $this->command->info('✅ Main data seeded successfully!');
    }
    
    private function seedSeasons()
    {
        $this->command->info('  Seeding seasons...');
        
        // Export actual data from dev database
        $seasons = [
            // This will be populated with actual data from dev database
        ];
        
        foreach ($seasons as $season) {
            DB::table('m_season')->updateOrInsert(
                ['name' => $season['name']],
                $season
            );
        }
        
        $this->command->line('    ✓ Seeded ' . count($seasons) . ' seasons');
    }
    
    private function seedLevels()
    {
        $this->command->info('  Seeding levels...');
        
        $levels = [
            // This will be populated with actual data from dev database
        ];
        
        foreach ($levels as $level) {
            DB::table('m_level')->updateOrInsert(
                ['name' => $level['name']],
                $level
            );
        }
        
        $this->command->line('    ✓ Seeded ' . count($levels) . ' levels');
    }
    
    private function seedRoomTypes()
    {
        $this->command->info('  Seeding room types...');
        
        $roomTypes = [
            // This will be populated with actual data from dev database
        ];
        
        foreach ($roomTypes as $roomType) {
            DB::table('m_room_type')->updateOrInsert(
                ['name' => $roomType['name']],
                $roomType
            );
        }
        
        $this->command->line('    ✓ Seeded ' . count($roomTypes) . ' room types');
    }
    
    private function seedRoomTypeGroups()
    {
        $this->command->info('  Seeding room type groups...');
        
        $roomTypeGroups = [
            // This will be populated with actual data from dev database
        ];
        
        foreach ($roomTypeGroups as $group) {
            DB::table('m_room_type_group')->updateOrInsert(
                ['name' => $group['name']],
                $group
            );
        }
        
        $this->command->line('    ✓ Seeded ' . count($roomTypeGroups) . ' room type groups');
    }
    
    private function seedParameters()
    {
        $this->command->info('  Seeding parameters...');
        
        $parameters = [
            // This will be populated with actual data from dev database
        ];
        
        foreach ($parameters as $parameter) {
            DB::table('m_parameter')->updateOrInsert(
                ['name' => $parameter['name']],
                $parameter
            );
        }
        
        $this->command->line('    ✓ Seeded ' . count($parameters) . ' parameters');
    }
    
    private function seedActivityTypes()
    {
        $this->command->info('  Seeding activity types...');
        
        $activityTypes = [
            // This will be populated with actual data from dev database
        ];
        
        foreach ($activityTypes as $activityType) {
            DB::table('m_activity_type')->updateOrInsert(
                ['name' => $activityType['name']],
                $activityType
            );
        }
        
        $this->command->line('    ✓ Seeded ' . count($activityTypes) . ' activity types');
    }
    
    private function seedActivityTypeDetails()
    {
        $this->command->info('  Seeding activity type details...');
        
        $activityTypeDetails = [
            // This will be populated with actual data from dev database
        ];
        
        foreach ($activityTypeDetails as $detail) {
            DB::table('m_activity_type_detail')->updateOrInsert(
                ['id' => $detail['id']],
                $detail
            );
        }
        
        $this->command->line('    ✓ Seeded ' . count($activityTypeDetails) . ' activity type details');
    }
    
    private function seedFirstPrograms()
    {
        $this->command->info('  Seeding first programs...');
        
        $firstPrograms = [
            // This will be populated with actual data from dev database
        ];
        
        foreach ($firstPrograms as $program) {
            DB::table('m_first_program')->updateOrInsert(
                ['name' => $program['name']],
                $program
            );
        }
        
        $this->command->line('    ✓ Seeded ' . count($firstPrograms) . ' first programs');
    }
    
    private function seedInsertPoints()
    {
        $this->command->info('  Seeding insert points...');
        
        $insertPoints = [
            // This will be populated with actual data from dev database
        ];
        
        foreach ($insertPoints as $point) {
            DB::table('m_insert_point')->updateOrInsert(
                ['id' => $point['id']],
                $point
            );
        }
        
        $this->command->line('    ✓ Seeded ' . count($insertPoints) . ' insert points');
    }
    
    private function seedRoles()
    {
        $this->command->info('  Seeding roles...');
        
        $roles = [
            // This will be populated with actual data from dev database
        ];
        
        foreach ($roles as $role) {
            DB::table('m_role')->updateOrInsert(
                ['name' => $role['name']],
                $role
            );
        }
        
        $this->command->line('    ✓ Seeded ' . count($roles) . ' roles');
    }
    
    private function seedVisibility()
    {
        $this->command->info('  Seeding visibility rules...');
        
        $visibility = [
            // This will be populated with actual data from dev database
        ];
        
        foreach ($visibility as $rule) {
            DB::table('m_visibility')->updateOrInsert(
                ['name' => $rule['name']],
                $rule
            );
        }
        
        $this->command->line('    ✓ Seeded ' . count($visibility) . ' visibility rules');
    }
    
    private function seedSupportedPlans()
    {
        $this->command->info('  Seeding supported plans...');
        
        $supportedPlans = [
            // This will be populated with actual data from dev database
        ];
        
        foreach ($supportedPlans as $plan) {
            DB::table('m_supported_plan')->updateOrInsert(
                ['name' => $plan['name']],
                $plan
            );
        }
        
        $this->command->line('    ✓ Seeded ' . count($supportedPlans) . ' supported plans');
    }
}