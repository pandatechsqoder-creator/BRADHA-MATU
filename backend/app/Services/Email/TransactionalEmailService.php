<?php

namespace App\Services\Email;

use App\Mail\AdminEmailTestMail;
use App\Mail\TrackedMailable;
use App\Mail\UserInvitationMail;
use App\Mail\VerificationCodeMail;
use App\Models\EmailDeliveryLog;
use App\Models\EmailProviderSetting;
use DateTimeInterface;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

final class TransactionalEmailService
{
    /**
     * @param  array<string, scalar|null>  $retryPayload
     */
    public function queue(
        TrackedMailable $mailable,
        string $recipient,
        string $template,
        array $retryPayload = [],
        ?DateTimeInterface $payloadExpiresAt = null,
    ): EmailDeliveryLog {
        $log = EmailDeliveryLog::create([
            'recipient' => $recipient,
            'template' => $template,
            'status' => 'queued',
            'queued_at' => now(),
            'payload' => $retryPayload,
            'payload_expires_at' => $payloadExpiresAt,
        ]);

        $this->dispatch($mailable, $recipient, $log);

        return $log;
    }

    public function retry(EmailDeliveryLog $log): EmailDeliveryLog
    {
        if ($log->status !== 'failed') {
            throw ValidationException::withMessages(['log' => ['Only failed email messages can be retried.']]);
        }

        if ($log->payload === null || ($log->payload_expires_at !== null && $log->payload_expires_at->isPast())) {
            $log->update(['payload' => null, 'payload_expires_at' => null]);
            throw ValidationException::withMessages(['log' => ['The secure retry data has expired. Send a new message instead.']]);
        }

        $payload = $log->payload;
        $mailable = match ($log->template) {
            'admin.test' => new AdminEmailTestMail,
            'auth.verification' => new VerificationCodeMail(
                (string) ($payload['recipient_name'] ?? ''),
                (string) ($payload['code'] ?? ''),
            ),
            'tenant.invitation' => new UserInvitationMail(
                (string) ($payload['recipient_email'] ?? ''),
                (string) ($payload['organisation_name'] ?? ''),
                (string) ($payload['role_name'] ?? ''),
                (string) ($payload['accept_url'] ?? ''),
                (string) ($payload['expires_at'] ?? ''),
            ),
            default => throw new LogicException('No safe retry handler is registered for this email template.'),
        };

        $log->update([
            'status' => 'queued',
            'queued_at' => now(),
            'retry_at' => now(),
            'failure_reason' => null,
            'provider_response' => null,
        ]);

        $this->dispatch($mailable, $log->recipient, $log);

        return $log->refresh();
    }

    public function purgeExpiredRetryPayloads(): int
    {
        return EmailDeliveryLog::query()
            ->whereNotNull('payload')
            ->whereNotNull('payload_expires_at')
            ->where('payload_expires_at', '<=', now())
            ->update([
                'payload' => null,
                'payload_expires_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function dispatch(TrackedMailable $mailable, string $recipient, EmailDeliveryLog $log): void
    {
        $this->applyProviderSettings();
        $mailable->trackDelivery($log->uuid);

        $settings = EmailProviderSetting::query()->latest('id')->first();
        if ($settings?->active && $settings->reply_to_address !== null) {
            $mailable->replyTo($settings->reply_to_address);
        }

        try {
            Mail::to($recipient)->queue($mailable->afterCommit());
        } catch (Throwable $exception) {
            $log->update([
                'status' => 'failed',
                'failure_reason' => $exception::class,
                'retry_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function applyProviderSettings(): void
    {
        $settings = EmailProviderSetting::query()->latest('id')->first();

        if ($settings === null || ! $settings->active) {
            return;
        }

        if ($settings->provider === 'log') {
            config(['mail.default' => 'log']);
            app('mail.manager')->purge();

            return;
        }

        $scheme = match (strtolower((string) $settings->encryption)) {
            'tls' => 'smtp',
            'ssl', 'smtps' => 'smtps',
            default => null,
        };

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $settings->host,
            'mail.mailers.smtp.port' => $settings->port,
            'mail.mailers.smtp.scheme' => $scheme,
            'mail.mailers.smtp.username' => $settings->username,
            'mail.mailers.smtp.password' => $settings->password,
            'mail.from.address' => $settings->from_address,
            'mail.from.name' => $settings->from_name,
        ]);

        app('mail.manager')->purge('smtp');
    }
}
