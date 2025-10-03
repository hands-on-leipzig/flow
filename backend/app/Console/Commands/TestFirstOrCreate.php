<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;

class TestFirstOrCreate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:first-or-create {subject}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test firstOrCreate behavior';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $subject = $this->argument('subject');
        
        $this->info("Testing firstOrCreate for subject: {$subject}");
        
        $user = User::firstOrCreate(
            ['subject' => $subject],
            [
                'subject' => $subject,
                'password' => null,
                'selection_event' => null,
                'selection_regional_partner' => null,
                'last_login' => now()
            ]
        );
        
        $this->line("User ID: {$user->id}");
        $this->line("Subject: {$user->subject}");
        $this->line("Was recently created: " . ($user->wasRecentlyCreated ? 'YES' : 'NO'));
        $this->line("Last login: {$user->last_login}");
        
        if (!$user->wasRecentlyCreated) {
            $this->info("Updating last_login for existing user...");
            $result = $user->update(['last_login' => now()]);
            $this->line("Update result: " . ($result ? 'SUCCESS' : 'FAILED'));
            $user->refresh();
            $this->line("New last_login: {$user->last_login}");
        }
        
        return 0;
    }
}
