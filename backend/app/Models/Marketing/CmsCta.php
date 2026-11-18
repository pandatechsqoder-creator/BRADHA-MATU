<?php

namespace App\Models\Marketing;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class CmsCta extends Model
{
    use HasPublicId;

    protected $table = 'cms_ctas';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
