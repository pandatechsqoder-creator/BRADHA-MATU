<?php

namespace App\Listeners;

use App\Models\EmailDeliveryLog;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;

final class TrackEmailProcessing
{
    public function handle(MessageSending $event): void
    {
        $header = $event->message->getHeaders()->get('X-BrathaMatu-Delivery-Id');
        if ($header === null) {
            return;
        }

        EmailDeliveryLog::query()
            ->where('uuid', $header->getBodyAsString())
            ->whereIn('status', ['queued', 'processing'])
            ->update([
                'status' => 'processing',
                'processing_at' => now(),
                'attempt_count' => DB::raw('attempt_count + 1'),
                'updated_at' => now(),
            ]);
    }
}
