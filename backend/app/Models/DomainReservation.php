<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DomainReservation extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (DomainReservation $reservation): void {
            $reservation->uuid ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
