<?php

namespace App\Models\Marketing;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsPageStructuredData extends Model
{
    use HasPublicId;

    protected $table = 'cms_page_structured_data';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'types' => 'array',
            'overrides' => 'array',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(CmsPage::class, 'page_id');
    }
}
