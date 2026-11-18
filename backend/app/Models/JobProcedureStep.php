<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobProcedureStep extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'evidence_required' => 'boolean',
        ];
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(JobProcedure::class, 'job_procedure_id');
    }
}
