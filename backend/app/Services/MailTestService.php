<?php

namespace App\Services;

use App\Mail\FlowTestMail;
use Illuminate\Support\Facades\Mail;

class MailTestService
{
    /**
     * @return array{mailer: string, from: string, configured: bool}
     */
    public function status(): array
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');
        $configured = filled($from);

        if ($mailer === 'microsoft-graph') {
            $graph = config('mail.mailers.microsoft-graph', []);
            $configured = filled($from)
                && filled($graph['tenant_id'] ?? null)
                && filled($graph['client_id'] ?? null)
                && filled($graph['client_secret'] ?? null);
        }

        return [
            'mailer' => $mailer,
            'from' => $from,
            'configured' => $configured,
        ];
    }

    public function sendTest(string $to): void
    {
        Mail::to($to)->send(new FlowTestMail);
    }
}
