<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosShift extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opening_float' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'actual_cash' => 'decimal:2',
            'cash_variance' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class, 'shift_id')->orderByDesc('created_at');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PosReceipt::class, 'shift_id')->orderByDesc('created_at');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PosReturn::class, 'shift_id')->orderByDesc('created_at');
    }

    public function totalSales(): float
    {
        return (float) $this->sales()->whereIn('status', ['completed', 'partially_returned', 'returned'])->sum('total_amount');
    }

    public function totalCashSales(): float
    {
        return (float) $this->sales()->whereIn('status', ['completed', 'partially_returned', 'returned'])->where('payment_method', 'cash')->sum('total_amount');
    }

    public function totalCashRefunds(): float
    {
        return (float) $this->returns()->where('status', 'completed')->where('refund_method', 'cash')->sum('total_amount');
    }
}
