<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventorySnapshot extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'snapshot_at' => 'datetime',
            'item_count' => 'integer',
            'total_quantity' => 'decimal:3',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventorySnapshotItem::class, 'snapshot_id')->orderBy('sku');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function catalogueVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogueVersion::class);
    }

    public function cutoff(): BelongsTo
    {
        return $this->belongsTo(InventoryCutoff::class, 'cutoff_id');
    }
}
