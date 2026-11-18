<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'metadata' => 'array',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(StocktakeJob::class, 'job_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(StocktakeQuote::class, 'quote_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class, 'payment_id')->orderBy('created_at');
    }

    public function callbacks(): HasMany
    {
        return $this->hasMany(PaymentCallback::class, 'payment_id')->orderBy('created_at');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'payment_id')->orderBy('created_at');
    }

    public function isSettled(): bool
    {
        return $this->status === 'succeeded';
    }
}
