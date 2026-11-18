<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTakerProfile extends Model
{
    use HasPublicId;

    public const LEVELS = ['applicant', 'trainee', 'verified', 'professional', 'expert', 'senior_supervisor'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'languages' => 'array',
            'experience_years' => 'integer',
            'completed_jobs_count' => 'integer',
            'verified_accuracy' => 'float',
            'reliability_score' => 'float',
            'phone_verified_at' => 'datetime',
            'identity_verified_at' => 'datetime',
            'training_completed_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(StockTakerVerification::class, 'profile_id')->latest('id');
    }

    public function skills(): HasMany
    {
        return $this->hasMany(StockTakerSkill::class, 'profile_id')->orderBy('skill');
    }

    public function serviceAreas(): HasMany
    {
        return $this->hasMany(StockTakerServiceArea::class, 'profile_id')->orderByDesc('is_primary')->orderBy('area');
    }

    public function availabilityWindows(): HasMany
    {
        return $this->hasMany(StockTakerAvailability::class, 'profile_id')->orderBy('weekday')->orderBy('start_time');
    }

    public function trainings(): HasMany
    {
        return $this->hasMany(StockTakerTrainingCompletion::class, 'profile_id')->orderByDesc('completed_at');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(JobAssignment::class, 'profile_id')->latest('id');
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(StockTakerEarning::class, 'profile_id')->latest('id');
    }

    public function isEligible(): bool
    {
        return $this->status === 'active'
            && $this->verification_status === 'verified'
            && in_array($this->level, ['verified', 'professional', 'expert', 'senior_supervisor'], true)
            && $this->training_completed_at !== null;
    }
}
