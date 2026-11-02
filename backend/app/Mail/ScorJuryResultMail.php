<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ScorJuryResultMail extends Mailable
{
    public function __construct(
        public readonly string $eventName,
        public readonly string $teamName,
        public readonly string $body,
        public readonly string $pdf,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Jurybewertung '.$this->teamName);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.scor-jury-result');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdf, 'jurybewertung.pdf')->withMime('application/pdf'),
        ];
    }
}
