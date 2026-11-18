<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeRequestDeclaration extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'request_snapshot' => 'array',
            'device' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(StocktakeJob::class, 'job_id');
    }

    public function catalogueVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogueVersion::class, 'catalogue_version_id');
    }

    public function declaredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
