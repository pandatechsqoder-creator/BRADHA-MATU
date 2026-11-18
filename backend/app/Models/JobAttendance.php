<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobAttendance extends Model
{
    use HasPublicId;

    protected $table = 'job_attendance';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scheduled_start_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'minutes_on_site' => 'integer',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(StocktakeJob::class, 'job_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(JobAssignment::class, 'assignment_id');
    }

    public function checkin(): BelongsTo
    {
        return $this->belongsTo(JobSiteCheckin::class, 'checkin_id');
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(JobSiteCheckout::class, 'checkout_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
