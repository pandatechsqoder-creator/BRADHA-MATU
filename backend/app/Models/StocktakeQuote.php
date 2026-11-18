<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeQuote extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'stocktake_job_quotes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'worker_compensation_breakdown' => 'array',
            'valid_until' => 'datetime',
            'issued_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'superseded_at' => 'datetime',
            'deposit_due_at' => 'datetime',
            'base_service_charge' => 'float',
            'scope_complexity_charge' => 'float',
            'travel_expenses' => 'float',
            'other_expenses' => 'float',
            'discount' => 'float',
            'subtotal' => 'float',
            'tax_rate' => 'float',
            'tax' => 'float',
            'total' => 'float',
            'deposit_amount' => 'float',
            'balance_due' => 'float',
            'worker_compensation_budget' => 'float',
            'platform_fee' => 'float',
            'margin_amount' => 'float',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(StocktakeJob::class, 'job_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
