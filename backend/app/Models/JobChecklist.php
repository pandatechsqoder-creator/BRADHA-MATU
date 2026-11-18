<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobChecklist extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(StocktakeJob::class, 'job_id');
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(JobProcedure::class, 'job_procedure_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(JobChecklistItem::class, 'checklist_id')->orderBy('position');
    }
}
