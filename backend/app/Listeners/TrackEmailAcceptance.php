<?php

namespace App\Listeners;

use App\Models\EmailDeliveryLog;
use Illuminate\Mail\Events\MessageSent;

final class TrackEmailAcceptance
{
    public function handle(MessageSent $event): void
    {
        $header = $event->sent->getOriginalMessage()->getHeaders()->get('X-BrathaMatu-Delivery-Id');
        if ($header === null) {
            return;
        }

        EmailDeliveryLog::query()
            ->where('uuid', $header->getBodyAsString())
            ->update([
                'status' => 'accepted',
                'provider_message_id' => $event->sent->getMessageId(),
                'provider_response' => ['message_id' => $event->sent->getMessageId()],
                'accepted_at' => now(),
                'payload' => null,
                'payload_expires_at' => null,
                'updated_at' => now(),
            ]);
    }
}
