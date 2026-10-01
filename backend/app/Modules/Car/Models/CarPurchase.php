<?php

namespace App\Modules\Car\Models;

use App\Modules\Car\Concerns\Reversible;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable purchase record; correct by reversing and recording a new purchase.
 * amount_minor is in minor units (2 decimals).
 */
#[Fillable(['car_id', 'branch_id', 'dealer_id', 'purchase_date', 'amount_minor', 'reference', 'notes', 'recorded_by'])]
class CarPurchase extends Model
{
    use HasUlids, Reversible;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'amount_minor' => 'integer',
            'reversed_at' => 'datetime',
        ];
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(CarDealer::class, 'dealer_id');
    }
}
