<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CountEntry extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw_quantity' => 'decimal:6',
            'normalized_quantity' => 'decimal:6',
            'observations' => 'array',
            'evidence' => 'array',
            'captured_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CountSession::class, 'session_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function rawUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'raw_unit_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CountEntryRevision::class, 'entry_id')->orderBy('created_at');
    }
}
