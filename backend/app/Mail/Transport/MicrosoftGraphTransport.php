<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Stringable;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

class MicrosoftGraphTransport extends AbstractTransport implements Stringable
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly bool $saveToSentItems = false,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $from = $email->getFrom()[0] ?? null;
        if (! $from instanceof Address) {
            throw new TransportException('Microsoft Graph mail requires a From address (MAIL_FROM_ADDRESS).');
        }

        $payload = $this->toGraphPayload($email);
        $mailbox = rawurlencode($from->getAddress());
        $response = Http::withToken($this->accessToken())
            ->post(self::GRAPH_BASE.'/users/'.$mailbox.'/sendMail', $payload);

        if (! $response->successful()) {
            Log::error('Microsoft Graph sendMail failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new TransportException(
                'Microsoft Graph sendMail failed (HTTP '.$response->status().').'
            );
        }
    }

    public function __toString(): string
    {
        return 'microsoft-graph';
    }

    /**
     * @return array<string, mixed>
     */
    private function toGraphPayload(Email $email): array
    {
        $html = $email->getHtmlBody();
        $text = $email->getTextBody();

        $message = [
            'subject' => $email->getSubject() ?? '',
            'body' => [
                'contentType' => $html !== null && $html !== '' ? 'HTML' : 'Text',
                'content' => $html !== null && $html !== '' ? $html : (string) $text,
            ],
            'toRecipients' => $this->mapAddresses($email->getTo()),
        ];

        $cc = $this->mapAddresses($email->getCc());
        if ($cc !== []) {
            $message['ccRecipients'] = $cc;
        }

        $bcc = $this->mapAddresses($email->getBcc());
        if ($bcc !== []) {
            $message['bccRecipients'] = $bcc;
        }

        $replyTo = $this->mapAddresses($email->getReplyTo());
        if ($replyTo !== []) {
            $message['replyTo'] = $replyTo;
        }

        $attachments = $this->mapAttachments($email);
        if ($attachments !== []) {
            $message['attachments'] = $attachments;
        }

        return [
            'message' => $message,
            'saveToSentItems' => $this->saveToSentItems,
        ];
    }

    /**
     * @param  Address[]  $addresses
     * @return list<array{emailAddress: array{address: string, name?: string}}>
     */
    private function mapAddresses(array $addresses): array
    {
        return array_values(array_map(function (Address $address) {
            $entry = ['address' => $address->getAddress()];
            if ($address->getName() !== '') {
                $entry['name'] = $address->getName();
            }

            return ['emailAddress' => $entry];
        }, $addresses));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapAttachments(Email $email): array
    {
        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $attachments[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $attachment->getFilename() ?: 'attachment',
                'contentType' => $attachment->getContentType(),
                'contentBytes' => base64_encode($attachment->getBody()),
            ];
        }

        return $attachments;
    }

    private function accessToken(): string
    {
        if ($this->tenantId === '' || $this->clientId === '' || $this->clientSecret === '') {
            throw new TransportException(
                'Microsoft Graph mail is not configured. Set MICROSOFT_GRAPH_TENANT_ID, CLIENT_ID and CLIENT_SECRET.'
            );
        }

        $cacheKey = 'microsoft_graph_mail_access_token_'.$this->clientId;

        return Cache::remember($cacheKey, 3500, function () {
            $response = Http::asForm()->post(
                "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
                [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]
            );

            if (! $response->successful()) {
                Log::error('Microsoft Graph mail token request failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                throw new TransportException(
                    'Azure-Anmeldung für Mail fehlgeschlagen. Prüfe Tenant-ID, Client-ID und Client-Secret.'
                );
            }

            $token = $response->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new TransportException('Kein Zugriffstoken von Azure für Mail erhalten.');
            }

            return $token;
        });
    }
}
