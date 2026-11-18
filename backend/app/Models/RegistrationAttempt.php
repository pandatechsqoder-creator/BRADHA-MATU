<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class RegistrationAttempt extends Model
{
    protected $guarded = [];

    protected $hidden = ['password_hash'];

    protected static function booted(): void
    {
        static::creating(function (RegistrationAttempt $attempt): void {
            $attempt->uuid ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'verification_expires_at' => 'datetime',
            'expires_at' => 'datetime',
            'domain_accepted_at' => 'datetime',
            'last_code_sent_at' => 'datetime',
        ];
    }

    public function codes(): HasMany
    {
        return $this->hasMany(RegistrationVerificationCode::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
