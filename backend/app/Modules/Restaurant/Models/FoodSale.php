<?php

namespace App\Modules\Restaurant\Models;

use App\Modules\Branch\Concerns\BelongsToBranch;
use App\Modules\Restaurant\Concerns\Reversible;
use App\Modules\Restaurant\Policies\FoodSalePolicy;
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
 * Food sale (branch-scoped, immutable apart from reversal). Figures come from SaleFinancials.
 */
#[Table('restaurant_sales')]
#[Fillable(['sale_no', 'branch_id', 'customer_id', 'sold_at', 'total_minor', 'notes', 'recorded_by'])]
#[UsePolicy(FoodSalePolicy::class)]
class FoodSale extends Model
{
    use BelongsToBranch, HasUlids, Reversible;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'reversed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(RestaurantCustomer::class, 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FoodSaleItem::class, 'sale_id')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FoodSalePayment::class, 'sale_id')->orderBy('payment_date')->orderBy('id');
    }

    /** SQL for the sum of active payments of the outer sale row. */
    public static function paidSql(): string
    {
        return '(SELECT coalesce(sum(p.amount_minor), 0) FROM restaurant_sale_payments p'
            .' WHERE p.sale_id = restaurant_sales.id AND p.reversed_at IS NULL)';
    }

    /** Adds paid_minor (active payments) to each row in one query. */
    public function scopeWithPaid(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('restaurant_sales.*');
        }

        return $query->addSelect(DB::raw(self::paidSql().' AS paid_minor'));
    }
}
