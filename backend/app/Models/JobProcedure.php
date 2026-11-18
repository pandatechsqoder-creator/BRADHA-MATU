<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobProcedure extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'require_acknowledgement' => 'boolean',
            'assigned_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(StocktakeJob::class, 'job_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProcedureTemplate::class, 'template_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(ProcedureTemplateVersion::class, 'template_version_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * The `steps` JSON column holds the immutable version snapshot; this relation
     * exposes the queryable rows. Named distinctly because the column shadows
     * a same-named relation on attribute access.
     */
    public function procedureSteps(): HasMany
    {
        return $this->hasMany(JobProcedureStep::class, 'job_procedure_id')->orderBy('position');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(JobProcedureAcknowledgement::class, 'job_procedure_id');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(JobChecklist::class, 'job_procedure_id');
    }
}
