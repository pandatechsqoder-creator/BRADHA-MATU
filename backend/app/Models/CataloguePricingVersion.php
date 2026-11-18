<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CataloguePricingVersion extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'effective_from' => 'datetime',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(CataloguePricingPolicy::class, 'policy_id');
    }
}
