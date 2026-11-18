<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JobAssignment extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'compensation_amount' => 'float',
            'offer_expires_at' => 'datetime',
            'offered_at' => 'datetime',
            'responded_at' => 'datetime',
            'accepted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(StocktakeJob::class, 'job_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(StockTakerProfile::class, 'profile_id');
    }

    public function offeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'offered_by');
    }

    public function replacedAssignment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_assignment_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(JobAssignmentEvent::class, 'assignment_id')->orderBy('id');
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(StockTakerEarning::class, 'assignment_id');
    }

    public function procedureAcknowledgements(): HasMany
    {
        return $this->hasMany(JobProcedureAcknowledgement::class, 'assignment_id');
    }

    public function checklistResponses(): HasMany
    {
        return $this->hasMany(JobChecklistResponse::class, 'assignment_id');
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(JobSiteCheckin::class, 'assignment_id');
    }

    public function checkouts(): HasMany
    {
        return $this->hasMany(JobSiteCheckout::class, 'assignment_id');
    }

    public function attendanceRecord(): HasOne
    {
        return $this->hasOne(JobAttendance::class, 'assignment_id');
    }
}
