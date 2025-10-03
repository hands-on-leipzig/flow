<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TestUserUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:user-update {subject}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test user last_login update functionality';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $subject = $this->argument('subject');
        
        $this->info("Testing user update for subject: {$subject}");
        
        // Check if user exists
        $user = User::where('subject', $subject)->first();
        
        if (!$user) {
            $this->error("User with subject '{$subject}' not found");
            return 1;
        }
        
        $this->info("Found user:");
        $this->line("  ID: {$user->id}");
        $this->line("  Subject: {$user->subject}");
        $this->line("  Current last_login: {$user->last_login}");
        
        // Test update
        $this->info("Updating last_login...");
        $updateResult = $user->update(['last_login' => now()]);
        
        $this->line("Update result: " . ($updateResult ? 'SUCCESS' : 'FAILED'));
        
        // Refresh and check
        $user->refresh();
        $this->line("New last_login: {$user->last_login}");
        
        // Also test direct DB update
        $this->info("Testing direct DB update...");
        $dbResult = DB::table('user')
            ->where('id', $user->id)
            ->update(['last_login' => now()]);
            
        $this->line("Direct DB update result: " . ($dbResult ? 'SUCCESS' : 'FAILED'));
        
        // Check final state
        $user->refresh();
        $this->line("Final last_login: {$user->last_login}");
        
        return 0;
    }
}
