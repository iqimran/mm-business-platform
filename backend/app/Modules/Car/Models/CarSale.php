<?php

namespace App\Modules\Car\Models;

use App\Modules\Car\Concerns\Reversible;
use App\Modules\Car\Enums\CarStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable sale record; at most one active sale per car. Correct by reversing (which
 * restores the car's previous status) and recording a new sale.
 */
#[Fillable(['car_id', 'branch_id', 'party_id', 'sale_date', 'amount_minor', 'status_before_sale', 'reference', 'notes', 'recorded_by'])]
class CarSale extends Model
{
    use HasUlids, Reversible;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'amount_minor' => 'integer',
            'status_before_sale' => CarStatus::class,
            'reversed_at' => 'datetime',
        ];
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(CarParty::class, 'party_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CarPartyPayment::class, 'sale_id');
    }
}
