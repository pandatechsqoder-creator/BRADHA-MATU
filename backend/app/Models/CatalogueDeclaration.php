<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogueDeclaration extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'device' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    public function catalogue(): BelongsTo
    {
        return $this->belongsTo(Catalogue::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(CatalogueVersion::class, 'catalogue_version_id');
    }

    public function declaredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }
}
