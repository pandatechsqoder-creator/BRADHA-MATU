<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogueVersion extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = [];

    public function catalogue(): BelongsTo
    {
        return $this->belongsTo(Catalogue::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(CatalogueImport::class, 'import_id');
    }
}
