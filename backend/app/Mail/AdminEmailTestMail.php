<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class AdminEmailTestMail extends TrackedMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'BrathaMatu email configuration test');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin.test');
    }
}
