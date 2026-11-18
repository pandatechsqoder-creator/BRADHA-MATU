<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CountEntryRevision extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'raw_quantity' => 'decimal:6',
        'normalized_quantity' => 'decimal:6',
        'observations' => 'array',
        'created_at' => 'datetime',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(CountEntry::class, 'entry_id');
    }

    public function rawUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'raw_unit_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
