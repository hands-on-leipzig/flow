<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PublicOtpMail extends Mailable
{
    public function __construct(
        public readonly string $eventName,
        public readonly string $code,
        public readonly ?string $intendedEmail = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Code für '.$this->eventName);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.public-otp');
    }
}
