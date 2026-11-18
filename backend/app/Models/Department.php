<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = [];
}
