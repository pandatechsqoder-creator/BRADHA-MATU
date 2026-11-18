<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailProviderSetting extends Model
{
    protected $guarded = [];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'active' => 'boolean'];
    }
}
