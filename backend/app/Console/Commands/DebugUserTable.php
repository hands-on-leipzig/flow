<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DebugUserTable extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'debug:user-table';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug user table structure and data';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info("Debugging user table...");
        
        // Check table structure
        $this->info("1. Table structure:");
        $columns = DB::select("DESCRIBE user");
        foreach ($columns as $column) {
            $this->line("  {$column->Field}: {$column->Type} " . 
                       ($column->Null === 'YES' ? 'NULL' : 'NOT NULL') . 
                       ($column->Default ? " DEFAULT {$column->Default}" : ''));
        }
        
        // Check if last_login column exists
        $hasLastLogin = collect($columns)->contains('Field', 'last_login');
        $this->line("Has last_login column: " . ($hasLastLogin ? 'YES' : 'NO'));
        
        // Show sample data
        $this->info("\n2. Sample user data:");
        $users = User::limit(3)->get();
        foreach ($users as $user) {
            $this->line("  ID: {$user->id}, Subject: {$user->subject}, Last Login: {$user->last_login}");
        }
        
        // Test direct SQL update
        $this->info("\n3. Testing direct SQL update:");
        $testUserId = $users->first()?->id;
        if ($testUserId) {
            $beforeUpdate = DB::table('user')->where('id', $testUserId)->value('last_login');
            $this->line("Before update: {$beforeUpdate}");
            
            $result = DB::table('user')
                ->where('id', $testUserId)
                ->update(['last_login' => now()]);
                
            $this->line("Update result: " . ($result ? 'SUCCESS' : 'FAILED'));
            
            $afterUpdate = DB::table('user')->where('id', $testUserId)->value('last_login');
            $this->line("After update: {$afterUpdate}");
        }
        
        return 0;
    }
}
