<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class VerificationCodeMail extends TrackedMailable
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your BrathaMatu verification code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.auth.verification-code');
    }
}
