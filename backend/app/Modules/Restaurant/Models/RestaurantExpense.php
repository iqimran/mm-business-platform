<?php

namespace App\Modules\Restaurant\Models;

use App\Modules\Branch\Concerns\BelongsToBranch;
use App\Modules\Restaurant\Concerns\Reversible;
use App\Modules\Restaurant\Policies\RestaurantExpensePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Restaurant expense (branch-scoped, immutable apart from reversal). Not related to car expenses.
 */
#[Table('restaurant_expenses')]
#[Fillable(['branch_id', 'category_id', 'supplier_id', 'expense_date', 'amount_minor', 'description', 'reference', 'recorded_by'])]
#[UsePolicy(RestaurantExpensePolicy::class)]
class RestaurantExpense extends Model
{
    use BelongsToBranch, HasUlids, Reversible;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount_minor' => 'integer',
            'reversed_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(RestaurantSupplier::class, 'supplier_id');
    }
}
