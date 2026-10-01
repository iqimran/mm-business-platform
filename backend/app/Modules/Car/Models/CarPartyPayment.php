<?php

namespace App\Modules\Car\Models;

use App\Modules\Car\Concerns\Reversible;
use App\Modules\Car\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received from the party (customer) against a sale. Reduces Party Due only.
 */
#[Fillable(['sale_id', 'car_id', 'branch_id', 'party_id', 'payment_date', 'amount_minor', 'method', 'reference', 'notes', 'recorded_by'])]
class CarPartyPayment extends Model
{
    use HasUlids, Reversible;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount_minor' => 'integer',
            'method' => PaymentMethod::class,
            'reversed_at' => 'datetime',
        ];
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(CarSale::class, 'sale_id');
    }
}
