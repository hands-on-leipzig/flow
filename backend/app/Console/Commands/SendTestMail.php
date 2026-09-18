<?php

namespace App\Console\Commands;

use App\Services\MailTestService;
use Illuminate\Console\Command;

class SendTestMail extends Command
{
    protected $signature = 'mail:test {email : Recipient address}';

    protected $description = 'Send a test message through the configured mailer (Microsoft Graph)';

    public function handle(MailTestService $mailTest): int
    {
        $email = $this->argument('email');
        $mailer = (string) config('mail.default');

        $mailTest->sendTest($email);

        $this->info("Sent test mail to {$email} via {$mailer}.");

        return self::SUCCESS;
    }
}
