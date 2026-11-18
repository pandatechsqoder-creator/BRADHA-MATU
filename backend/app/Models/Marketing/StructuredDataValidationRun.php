<?php

namespace App\Models\Marketing;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StructuredDataValidationRun extends Model
{
    use HasPublicId;

    protected $table = 'structured_data_validation_runs';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'types' => 'array',
            'issues' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(CmsPage::class, 'page_id');
    }
}
