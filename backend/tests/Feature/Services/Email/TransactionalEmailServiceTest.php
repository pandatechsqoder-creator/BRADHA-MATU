<?php

namespace Tests\Feature\Services\Email;

use App\Listeners\TrackEmailAcceptance;
use App\Listeners\TrackEmailProcessing;
use App\Mail\UserInvitationMail;
use App\Mail\VerificationCodeMail;
use App\Models\EmailDeliveryLog;
use App\Services\Email\TransactionalEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage as LaravelSentMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class TransactionalEmailServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_queueing_a_message_tracks_it_without_exposing_retry_payload(): void
    {
        Mail::fake();

        $log = app(TransactionalEmailService::class)->queue(
            new VerificationCodeMail('Jane Mwangi', '123456'),
            'jane@example.com',
            'auth.verification',
            ['recipient_name' => 'Jane Mwangi', 'code' => '123456'],
            now()->addMinutes(15),
        );

        Mail::assertQueued(
            VerificationCodeMail::class,
            fn (VerificationCodeMail $mail): bool => $mail->code === '123456'
                && $mail->headers()->text['X-BrathaMatu-Delivery-Id'] === $log->uuid,
        );

        $this->assertSame('queued', $log->status);
        $this->assertSame(['recipient_name' => 'Jane Mwangi', 'code' => '123456'], $log->payload);
        $this->assertArrayNotHasKey('payload', $log->toArray());
    }

    public function test_processing_event_updates_attempt_count_for_a_tracked_message(): void
    {
        $log = EmailDeliveryLog::create([
            'recipient' => 'jane@example.com',
            'template' => 'auth.verification',
            'status' => 'queued',
            'queued_at' => now(),
        ]);
        $message = (new Email)
            ->from('no-reply@example.com')
            ->to('jane@example.com')
            ->text('test');
        $message->getHeaders()->addTextHeader('X-BrathaMatu-Delivery-Id', $log->uuid);

        app(TrackEmailProcessing::class)->handle(new MessageSending($message));

        $this->assertDatabaseHas('email_delivery_logs', [
            'uuid' => $log->uuid,
            'status' => 'processing',
            'attempt_count' => 1,
        ]);
    }

    public function test_provider_acceptance_records_message_id_and_erases_retry_payload(): void
    {
        $log = EmailDeliveryLog::create([
            'recipient' => 'jane@example.com',
            'template' => 'auth.verification',
            'status' => 'processing',
            'queued_at' => now(),
            'payload' => ['recipient_name' => 'Jane', 'code' => '123456'],
            'payload_expires_at' => now()->addMinutes(15),
        ]);
        $message = (new Email)
            ->from('no-reply@example.com')
            ->to('jane@example.com')
            ->text('test');
        $message->getHeaders()->addTextHeader('X-BrathaMatu-Delivery-Id', $log->uuid);
        $sentMessage = new LaravelSentMessage(new SymfonySentMessage(
            $message,
            new Envelope(new Address('no-reply@example.com'), [new Address('jane@example.com')]),
        ));

        app(TrackEmailAcceptance::class)->handle(new MessageSent($sentMessage));

        $this->assertSame('accepted', $log->fresh()->status);
        $this->assertSame('jane@example.com', $log->fresh()->recipient);
        $this->assertNull($log->fresh()->payload);
        $this->assertNotEmpty($log->fresh()->provider_message_id);
    }

    public function test_failed_invitation_can_be_requeued_with_its_encrypted_payload(): void
    {
        Mail::fake();
        $log = EmailDeliveryLog::create([
            'recipient' => 'jane@example.com',
            'template' => 'tenant.invitation',
            'status' => 'failed',
            'failure_reason' => 'TransportException',
            'queued_at' => now()->subMinute(),
            'payload' => [
                'recipient_email' => 'jane@example.com',
                'organisation_name' => 'Green Valley',
                'role_name' => 'Inventory Manager',
                'accept_url' => 'https://example.test/invitations/accept?token=secure',
                'expires_at' => now()->addDays(7)->toIso8601String(),
            ],
            'payload_expires_at' => now()->addDays(7),
        ]);

        $retried = app(TransactionalEmailService::class)->retry($log);

        Mail::assertQueued(UserInvitationMail::class);
        $this->assertSame('queued', $retried->status);
        $this->assertSame($log->uuid, $retried->uuid);
    }

    public function test_expired_retry_payload_is_erased_and_not_requeued(): void
    {
        Mail::fake();
        $log = EmailDeliveryLog::create([
            'recipient' => 'jane@example.com',
            'template' => 'auth.verification',
            'status' => 'failed',
            'queued_at' => now()->subHour(),
            'payload' => ['recipient_name' => 'Jane', 'code' => '123456'],
            'payload_expires_at' => now()->subMinute(),
        ]);

        try {
            app(TransactionalEmailService::class)->retry($log);
            $this->fail('Expired verification data must not be requeued.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('log', $exception->errors());
        }

        Mail::assertNothingQueued();
        $this->assertNull($log->fresh()->payload);
    }

    public function test_expired_encrypted_retry_payloads_are_purged(): void
    {
        $expiredLog = EmailDeliveryLog::create([
            'recipient' => 'expired@example.com',
            'template' => 'auth.verification',
            'status' => 'failed',
            'queued_at' => now()->subHour(),
            'payload' => ['recipient_name' => 'Jane', 'code' => '123456'],
            'payload_expires_at' => now()->subMinute(),
        ]);
        $activeLog = EmailDeliveryLog::create([
            'recipient' => 'active@example.com',
            'template' => 'auth.verification',
            'status' => 'failed',
            'queued_at' => now(),
            'payload' => ['recipient_name' => 'John', 'code' => '654321'],
            'payload_expires_at' => now()->addMinute(),
        ]);

        $purged = app(TransactionalEmailService::class)->purgeExpiredRetryPayloads();

        $this->assertSame(1, $purged);
        $this->assertNull($expiredLog->fresh()->payload);
        $this->assertNotNull($activeLog->fresh()->payload);
    }

    public function test_invitation_template_escapes_business_and_role_content(): void
    {
        $mail = new UserInvitationMail(
            'jane@example.com',
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
            'https://example.test/invite',
            now()->addDays(7)->toIso8601String(),
        );

        $html = $mail->render();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
    }
}
