<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogueImport extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'mapping' => 'array',
            'size' => 'integer',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(CatalogueImportRow::class, 'import_id')->orderBy('row_number');
    }

    public function catalogue(): BelongsTo
    {
        return $this->belongsTo(Catalogue::class);
    }

    public function committedVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogueVersion::class, 'committed_version_id');
    }
}
