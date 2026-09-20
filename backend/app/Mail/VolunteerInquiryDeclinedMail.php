<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VolunteerInquiryDeclinedMail extends Mailable
{
    public function __construct(
        public readonly string $eventName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Rückmeldung zur Veranstaltung '.$this->eventName);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.volunteer-inquiry-declined');
    }
}
