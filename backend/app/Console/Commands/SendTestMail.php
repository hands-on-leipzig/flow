<?php

namespace App\Console\Commands;

use App\Mail\Catalog\MailNotificationCatalog;
use Illuminate\Console\Command;

class SendTestMail extends Command
{
    protected $signature = 'mail:test {email : Recipient address} {--key=admin-test : Catalog notification key}';

    protected $description = 'Send a catalog sample mail through the configured mailer';

    public function handle(MailNotificationCatalog $catalog): int
    {
        $email = $this->argument('email');
        $key = (string) $this->option('key');
        $mailer = (string) config('mail.default');

        $catalog->sendSample($key, $email);

        $this->info("Sent [{$key}] to {$email} via {$mailer}.");

        return self::SUCCESS;
    }
}
