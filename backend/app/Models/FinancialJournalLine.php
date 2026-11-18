<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FinancialJournalLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'debit' => 'float',
            'credit' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Journal lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Journal lines are immutable.'));
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinancialJournal::class, 'journal_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'account_id');
    }
}
