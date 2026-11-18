<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StocktakeJob extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'preferred_start_at' => 'datetime',
            'deadline_at' => 'datetime',
            'scope' => 'array',
            'product_scope_payload' => 'array',
            'required_skills' => 'array',
            'required_evidence' => 'array',
            'attachments' => 'array',
            'provisional_estimate' => 'array',
            'deposit_required' => 'boolean',
            'deposit_due_at' => 'datetime',
            'submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'quoted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancellation' => 'array',
            'closed_at' => 'datetime',
            'pos_commissioned' => 'boolean',
            'recurrence_config' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'authorised_at' => 'datetime',
            'deposit_amount' => 'float',
            'deposit_paid_amount' => 'float',
            'quote_total' => 'float',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function prepaidAgreement(): BelongsTo
    {
        return $this->belongsTo(PrepaidAgreement::class, 'prepaid_agreement_id');
    }

    public function authorisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorised_by');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(StocktakeQuote::class, 'job_id');
    }

    public function latestQuote(): HasOne
    {
        return $this->hasOne(StocktakeQuote::class, 'job_id')->latestOfMany('version');
    }

    public function acceptedQuote(): BelongsTo
    {
        return $this->belongsTo(StocktakeQuote::class, 'accepted_quote_id');
    }

    public function deposit(): HasOne
    {
        return $this->hasOne(StocktakeDeposit::class, 'job_id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(StocktakeJobAmendment::class, 'job_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(StocktakeStatusHistory::class, 'job_id')->orderBy('created_at');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(JobAssignment::class, 'job_id')->latest('id');
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(JobProcedure::class, 'job_id')->latest('id');
    }

    public function currentProcedure(): HasOne
    {
        return $this->hasOne(JobProcedure::class, 'job_id')->where('status', 'active')->latestOfMany('id');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(JobChecklist::class, 'job_id');
    }

    public function siteCheckins(): HasMany
    {
        return $this->hasMany(JobSiteCheckin::class, 'job_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(JobAttendance::class, 'job_id');
    }

    public function progressEvents(): HasMany
    {
        return $this->hasMany(JobProgressEvent::class, 'job_id')->orderBy('occurred_at');
    }

    public function catalogue(): BelongsTo
    {
        return $this->belongsTo(Catalogue::class);
    }

    public function catalogueVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogueVersion::class);
    }

    public function requestDeclarations(): HasMany
    {
        return $this->hasMany(StocktakeRequestDeclaration::class, 'job_id')->orderByDesc('revision');
    }

    public function inventorySnapshot(): BelongsTo
    {
        return $this->belongsTo(InventorySnapshot::class);
    }
}
