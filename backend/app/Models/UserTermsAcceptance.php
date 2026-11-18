<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserTermsAcceptance extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }
}