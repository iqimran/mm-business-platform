<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Shared\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Filtered, branch-scoped food sale listing and its totals (server-side).
 *
 * @phpstan-type Filters array{branch_id?: string, customer_id?: string, date_from?: string, date_to?: string,
 *                             payment_status?: string, state?: string, search?: string}
 */
class FoodSaleQuery
{
    /**
     * @param  Filters  $filters
     */
    public function filtered(User $user, array $filters): Builder
    {
        $paid = FoodSale::paidSql();

        return FoodSale::query()
            ->accessibleBy($user)
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('restaurant_sales.branch_id', $id))
            ->when($filters['customer_id'] ?? null, fn ($q, $id) => $q->where('restaurant_sales.customer_id', $id))
            // Calendar days in the application time zone, as an index-friendly range.
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('restaurant_sales.sold_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('restaurant_sales.sold_at', '<', Carbon::parse($d)->addDay()->startOfDay()))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('restaurant_sales.sale_no', 'ilike', "%{$term}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$term}%")->orWhere('phone', 'ilike', "%{$term}%"))))
            ->when(($filters['state'] ?? null) === 'active', fn ($q) => $q->whereNull('restaurant_sales.reversed_at'))
            ->when(($filters['state'] ?? null) === 'reversed', fn ($q) => $q->whereNotNull('restaurant_sales.reversed_at'))
            ->when($filters['payment_status'] ?? null, fn ($q, $status) => $q
                ->whereNull('restaurant_sales.reversed_at')
                ->whereRaw(match ($status) {
                    'unpaid' => "{$paid} = 0",
                    'partial' => "{$paid} > 0 AND {$paid} < restaurant_sales.total_minor",
                    default => "{$paid} >= restaurant_sales.total_minor",
                }));
    }

    /**
     * Totals of the filtered, non-reversed sales.
     *
     * @return array{count: int, total: string, paid: string, due: string}
     */
    public function summary(Builder $filtered): array
    {
        $row = DB::query()
            ->fromSub($filtered->clone()->whereNull('restaurant_sales.reversed_at')->toBase()
                ->select('restaurant_sales.total_minor')->selectRaw(FoodSale::paidSql().' AS paid_minor'), 's')
            ->selectRaw('count(*) AS sales, coalesce(sum(total_minor), 0) AS total, coalesce(sum(paid_minor), 0) AS paid')
            ->first();

        return [
            'count' => (int) $row->sales,
            'total' => Money::toDecimal((int) $row->total),
            'paid' => Money::toDecimal((int) $row->paid),
            'due' => Money::toDecimal((int) $row->total - (int) $row->paid),
        ];
    }
}
