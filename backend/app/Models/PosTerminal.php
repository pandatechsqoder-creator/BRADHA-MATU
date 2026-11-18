<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosTerminal extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(PosShift::class, 'terminal_id')->orderByDesc('opened_at');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class, 'terminal_id')->orderByDesc('created_at');
    }

    public function currentShift(): ?PosShift
    {
        return $this->shifts()->where('status', 'open')->first();
    }
}
