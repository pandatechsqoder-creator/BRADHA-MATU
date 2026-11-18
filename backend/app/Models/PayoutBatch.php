<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoutBatch extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_amount' => 'float',
            'prepared_at' => 'datetime', 'approved_at' => 'datetime',
            'processed_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function payouts(): HasMany { return $this->hasMany(StockTakerPayout::class, 'batch_id'); }
    public function preparedBy(): BelongsTo { return $this->belongsTo(User::class, 'prepared_by'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function approvals(): HasMany { return $this->hasMany(PayoutApproval::class, 'batch_id'); }
}
