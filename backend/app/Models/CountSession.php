<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CountSession extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'device' => 'array',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(StocktakeJob::class, 'job_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(JobAssignment::class, 'assignment_id');
    }

    public function catalogueVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogueVersion::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(CountSessionLocation::class, 'session_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CountEntry::class, 'session_id')->orderBy('id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(CountException::class, 'session_id');
    }

    public function unknownProducts(): HasMany
    {
        return $this->hasMany(UnknownProductReport::class, 'session_id');
    }

    public function lock(): HasOne
    {
        return $this->hasOne(CountSubmissionLock::class, 'session_id');
    }
}
