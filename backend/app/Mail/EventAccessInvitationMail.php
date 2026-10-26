<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class EventAccessInvitationMail extends Mailable
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public readonly string $eventName,
        public readonly array $permissions,
        public readonly string $loginUrl,
        public readonly string $expiresOn,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Einladung: '.$this->eventName);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.event-access-invitation');
    }
}
