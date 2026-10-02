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
 * Money paid to a supplier against one expense (bill). Reduces the supplier due; immutable apart from reversal.
 */
#[Table('restaurant_expense_payments')]
#[Fillable(['expense_id', 'branch_id', 'supplier_id', 'payment_date', 'amount_minor', 'method', 'reference', 'notes', 'recorded_by'])]
class RestaurantExpensePayment extends Model
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

    public function expense(): BelongsTo
    {
        return $this->belongsTo(RestaurantExpense::class, 'expense_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(RestaurantSupplier::class, 'supplier_id');
    }
}
