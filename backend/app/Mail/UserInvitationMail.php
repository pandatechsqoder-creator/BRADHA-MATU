<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class UserInvitationMail extends TrackedMailable
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $organisationName,
        public readonly string $roleName,
        public readonly string $acceptUrl,
        public readonly string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Invitation to join '.$this->organisationName.' on BrathaMatu');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invitations.user');
    }
}
