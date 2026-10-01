<?php

namespace App\Modules\Restaurant\Models;

use App\Modules\Restaurant\Concerns\Reversible;
use App\Modules\Restaurant\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received against a food sale. Reduces the sale's due; same branch as the sale.
 */
#[Table('restaurant_sale_payments')]
#[Fillable(['sale_id', 'branch_id', 'payment_date', 'amount_minor', 'method', 'reference', 'notes', 'recorded_by'])]
class FoodSalePayment extends Model
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

    public function sale(): BelongsTo
    {
        return $this->belongsTo(FoodSale::class, 'sale_id');
    }
}
