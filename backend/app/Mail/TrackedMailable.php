<?php

namespace App\Mail;

use App\Models\EmailDeliveryLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Throwable;

abstract class TrackedMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [30, 120, 600];

    private ?string $deliveryLogUuid = null;

    public function trackDelivery(string $uuid): static
    {
        $this->deliveryLogUuid = $uuid;

        return $this;
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->deliveryLogUuid === null
            ? []
            : ['X-BrathaMatu-Delivery-Id' => $this->deliveryLogUuid]);
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->deliveryLogUuid === null) {
            return;
        }

        EmailDeliveryLog::query()
            ->where('uuid', $this->deliveryLogUuid)
            ->whereNotIn('status', ['accepted', 'delivered'])
            ->update([
                'status' => 'failed',
                'failure_reason' => $exception === null ? 'Delivery failed.' : $exception::class,
                'retry_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
