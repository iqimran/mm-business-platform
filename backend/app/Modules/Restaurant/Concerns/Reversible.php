<?php

namespace App\Modules\Restaurant\Concerns;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable restaurant financial record that can only be reversed once
 * (enforced by restaurant_financial_record_guard()).
 */
trait Reversible
{
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** Records that count in financial totals. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('reversed_at'));
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
