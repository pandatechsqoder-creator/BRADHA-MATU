<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobChecklistItem extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'options' => 'array',
        ];
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(JobChecklist::class, 'checklist_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(JobChecklistResponse::class, 'checklist_item_id');
    }
}
