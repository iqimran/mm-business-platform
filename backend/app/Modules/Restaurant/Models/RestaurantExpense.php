<?php

namespace App\Modules\Restaurant\Models;

use App\Modules\Branch\Concerns\BelongsToBranch;
use App\Modules\Restaurant\Concerns\Reversible;
use App\Modules\Restaurant\Policies\RestaurantExpensePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Restaurant expense / supplier bill (branch-scoped, immutable apart from reversal). Not related to car expenses.
 * Without a supplier it is always fully paid; with a supplier, paid = active supplier payments (see ExpenseFinancials).
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
            'paid_minor' => 'integer',
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

    public function payments(): HasMany
    {
        return $this->hasMany(RestaurantExpensePayment::class, 'expense_id')->orderBy('payment_date')->orderBy('id');
    }

    /** SQL for the paid amount of the outer expense row (full amount when there is no supplier). */
    public static function paidSql(): string
    {
        return '(CASE WHEN restaurant_expenses.supplier_id IS NULL THEN restaurant_expenses.amount_minor ELSE'
            .' (SELECT coalesce(sum(p.amount_minor), 0) FROM restaurant_expense_payments p'
            .' WHERE p.expense_id = restaurant_expenses.id AND p.reversed_at IS NULL) END)';
    }

    /** Adds paid_minor to each row in one query. */
    public function scopeWithPaid(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('restaurant_expenses.*');
        }

        return $query->addSelect(DB::raw(self::paidSql().' AS paid_minor'));
    }
}
