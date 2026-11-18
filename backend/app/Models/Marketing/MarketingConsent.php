<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Model;

class MarketingConsent extends Model
{
    public $timestamps = false;

    public const CHANNELS = ['email', 'sms', 'whatsapp', 'analytics', 'marketing'];

    protected $table = 'marketing_consents';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }
}
