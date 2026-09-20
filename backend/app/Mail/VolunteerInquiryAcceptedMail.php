<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VolunteerInquiryAcceptedMail extends Mailable
{
    public function __construct(
        public readonly string $eventName,
        public readonly string $personName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Helfer:innenliste: '.$this->eventName);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.volunteer-inquiry-accepted');
    }
}
