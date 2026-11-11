<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TeamPlayersChangedMail extends Mailable
{
    public function __construct(
        public readonly string $eventName,
        public readonly string $teamName,
        public readonly string $flowUrl,
    ) {}

    public function envelope(): Envelope
    {
        $team = trim($this->teamName);

        return new Envelope(
            subject: $team === '' ? 'Teamdaten geändert' : 'Teamdaten geändert: '.$team,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.team-players-changed');
    }
}
