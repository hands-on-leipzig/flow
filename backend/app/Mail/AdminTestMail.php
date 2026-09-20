<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AdminTestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'FLOW Testmail');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.admin-test');
    }
}
