<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TeamDataDeadlineMail extends Mailable
{
    public function __construct(
        public readonly string $eventName,
        public readonly string $deadline,
        public readonly string $firstname = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Frist für Teamdaten: '.$this->eventName);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.team-data-deadline');
    }
}
