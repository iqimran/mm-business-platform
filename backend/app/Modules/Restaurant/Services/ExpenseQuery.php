<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\RestaurantExpense;
use App\Modules\Shared\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Branch-scoped restaurant expense queries: filtered list, totals and the daily category-wise summary.
 * Totals only count active (non-reversed) expenses.
 */
class ExpenseQuery
{
    /**
     * @param  array{branch_id?: string, category_id?: string, supplier_id?: string, date?: string,
     *               date_from?: string, date_to?: string, state?: string, search?: string, payment_status?: string}  $filters
     */
    public function filtered(User $user, array $filters): Builder
    {
        return RestaurantExpense::query()
            ->accessibleBy($user)
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('restaurant_expenses.branch_id', $id))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('restaurant_expenses.category_id', $id))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('restaurant_expenses.supplier_id', $id))
            ->when($filters['date'] ?? null, fn ($q, $d) => $q->where('restaurant_expenses.expense_date', $d))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('restaurant_expenses.expense_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('restaurant_expenses.expense_date', '<=', $d))
            ->when(($filters['state'] ?? null) === 'active', fn ($q) => $q->whereNull('restaurant_expenses.reversed_at'))
            ->when(($filters['state'] ?? null) === 'reversed', fn ($q) => $q->whereNotNull('restaurant_expenses.reversed_at'))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('restaurant_expenses.description', 'ilike', "%{$term}%")
                ->orWhere('restaurant_expenses.reference', 'ilike', "%{$term}%")))
            // Supplier dues: unpaid / partially paid / paid (active expenses only).
            ->when($filters['payment_status'] ?? null, function ($q, $status) {
                $paid = RestaurantExpense::paidSql();
                $q->whereNull('restaurant_expenses.reversed_at')->whereRaw(match ($status) {
                    'unpaid' => "{$paid} = 0",
                    'partial' => "{$paid} > 0 AND {$paid} < restaurant_expenses.amount_minor",
                    'due' => "{$paid} < restaurant_expenses.amount_minor",
                    default => "{$paid} >= restaurant_expenses.amount_minor",
                });
            });
    }

    /**
     * @return array{count: int, total: string, paid: string, supplier_due: string}
     */
    public function totals(Builder $filtered): array
    {
        $row = $filtered->clone()->toBase()
            ->whereNull('restaurant_expenses.reversed_at')
            ->selectRaw('count(*) AS expenses, coalesce(sum(restaurant_expenses.amount_minor), 0) AS total, coalesce(sum('.RestaurantExpense::paidSql().'), 0) AS paid')
            ->first();

        return [
            'count' => (int) $row->expenses,
            'total' => Money::toDecimal((int) $row->total),
            'paid' => Money::toDecimal((int) $row->paid),
            // Owed to suppliers for these expenses.
            'supplier_due' => Money::toDecimal((int) $row->total - (int) $row->paid),
        ];
    }

    /**
     * Daily category-wise totals: one entry per day (newest first) with each category's total,
     * plus category totals and the grand total for the whole range.
     *
     * @return array{days: list<array{date: string, total: string, categories: list<array{category_id: string, category: string, total: string, count: int}>}>,
     *               categories: list<array{category_id: string, category: string, total: string, count: int}>, total: string, count: int}
     */
    public function dailyCategorySummary(Builder $filtered): array
    {
        $rows = $filtered->clone()->toBase()
            ->whereNull('restaurant_expenses.reversed_at')
            ->join('restaurant_expense_categories as c', 'c.id', '=', 'restaurant_expenses.category_id')
            ->groupBy('restaurant_expenses.expense_date', 'c.id', 'c.name')
            ->orderByDesc('restaurant_expenses.expense_date')
            ->orderBy('c.name')
            ->select([
                DB::raw("to_char(restaurant_expenses.expense_date, 'YYYY-MM-DD') AS day"),
                'c.id AS category_id',
                'c.name AS category',
                DB::raw('sum(restaurant_expenses.amount_minor) AS total'),
                DB::raw('count(*) AS expenses'),
            ])
            ->get();

        $days = [];
        $categories = [];
        $grand = 0;
        $count = 0;

        foreach ($rows as $row) {
            $total = (int) $row->total;
            $days[$row->day] ??= ['date' => $row->day, 'total_minor' => 0, 'categories' => []];
            $days[$row->day]['total_minor'] += $total;
            $days[$row->day]['categories'][] = [
                'category_id' => $row->category_id, 'category' => $row->category, 'total' => Money::toDecimal($total), 'count' => (int) $row->expenses,
            ];

            $categories[$row->category_id] ??= ['category_id' => $row->category_id, 'category' => $row->category, 'total_minor' => 0, 'count' => 0];
            $categories[$row->category_id]['total_minor'] += $total;
            $categories[$row->category_id]['count'] += (int) $row->expenses;

            $grand += $total;
            $count += (int) $row->expenses;
        }

        uasort($categories, fn ($a, $b) => strcasecmp($a['category'], $b['category']));

        return [
            'days' => array_values(array_map(fn (array $day) => [
                'date' => $day['date'],
                'total' => Money::toDecimal($day['total_minor']),
                'categories' => $day['categories'],
            ], $days)),
            'categories' => array_values(array_map(fn (array $c) => [
                'category_id' => $c['category_id'], 'category' => $c['category'], 'total' => Money::toDecimal($c['total_minor']), 'count' => $c['count'],
            ], $categories)),
            'total' => Money::toDecimal($grand),
            'count' => $count,
        ];
    }
}
