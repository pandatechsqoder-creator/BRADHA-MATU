<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EmailDeliveryLog extends Model
{
    protected $guarded = [];

    protected $hidden = ['payload'];

    protected static function booted(): void
    {
        static::creating(function (EmailDeliveryLog $log): void {
            $log->uuid ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'provider_response' => 'array',
            'payload' => 'encrypted:array',
            'queued_at' => 'datetime',
            'processing_at' => 'datetime',
            'accepted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'retry_at' => 'datetime',
            'payload_expires_at' => 'datetime',
            'attempt_count' => 'integer',
        ];
    }
}
