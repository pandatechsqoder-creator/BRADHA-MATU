<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeDeposit extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'policy' => 'array',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'amount' => 'float',
            'paid_amount' => 'float',
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
}
