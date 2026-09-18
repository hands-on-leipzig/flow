<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VolunteerInquiryPlannerMail extends Mailable
{
    public function __construct(
        public readonly string $eventName,
        public readonly string $personName,
        public readonly string $role,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Neue Helfer:innen-Anfrage: '.$this->eventName);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.volunteer-inquiry-planner');
    }
}
