<?php

namespace App\Modules\Restaurant\Reports;

use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Services\ExpenseQuery;
use App\Modules\Restaurant\Services\FoodSaleQuery;
use App\Modules\Restaurant\Services\HallBookingQuery;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Restaurant financial summary for a period. Revenue streams, collections and expenses are reported
 * SEPARATELY: the restaurant domain defines no cost model, so no profit/net figure is calculated.
 *
 * - Food sale revenue:    active sales sold in the period (sale totals); received/due against those sales.
 * - Hall booking revenue: non-cancelled bookings whose event date is in the period (agreed amounts); received/due against them.
 * - Collected in period:  active payments dated in the period (cash view), whatever the document date.
 * - Expenses:             active expenses dated in the period.
 * Sections the user may not view are null.
 */
class FinancialSummary
{
    public function __construct(
        private readonly FoodSaleQuery $sales,
        private readonly HallBookingQuery $bookings,
        private readonly ExpenseQuery $expenses,
    ) {}

    /**
     * @param  array{date_from: string, date_to: string, branch_id?: string}  $period
     * @return array<string, mixed>
     */
    public function build(User $user, array $period): array
    {
        $filters = array_filter(['date_from' => $period['date_from'], 'date_to' => $period['date_to'], 'branch_id' => $period['branch_id'] ?? null]);

        return [
            'period' => ['date_from' => $period['date_from'], 'date_to' => $period['date_to']],
            'branch_id' => $period['branch_id'] ?? null,
            'food_sales' => $user->hasPermission('restaurant.sale.view') ? $this->foodSales($user, $filters) : null,
            'hall_bookings' => $user->hasPermission('restaurant.booking.view') ? $this->hallBookings($user, $filters) : null,
            'expenses' => $user->hasPermission('restaurant.expense.view') ? $this->expenses->totals($this->expenses->filtered($user, ['state' => 'active'] + $filters)) : null,
        ];
    }

    private function foodSales(User $user, array $filters): array
    {
        $summary = $this->sales->summary($this->sales->filtered($user, ['state' => 'active'] + $filters));

        return [
            'count' => $summary['count'],
            'revenue' => $summary['total'],
            'received' => $summary['paid'],
            'outstanding_due' => $summary['due'],
            'collected_in_period' => $this->collected($user, 'restaurant_sale_payments', $filters),
        ];
    }

    private function hallBookings(User $user, array $filters): array
    {
        $summary = $this->bookings->summary($this->bookings->filtered($user, $filters));

        return [
            'count' => $summary['count'],
            'revenue' => $summary['agreed_amount'],
            'received' => $summary['paid'],
            'outstanding_due' => $summary['due'],
            'collected_in_period' => $this->collected($user, 'restaurant_hall_booking_payments', $filters),
        ];
    }

    /** Active payments dated in the period, in the user's accessible branches. */
    private function collected(User $user, string $table, array $filters): string
    {
        $total = DB::table($table)
            ->whereNull('reversed_at')
            ->where('payment_date', '>=', $filters['date_from'])
            ->where('payment_date', '<=', $filters['date_to'])
            ->when(! $user->canAccessAllBranches(), fn ($q) => $q->whereIn('branch_id', $user->accessibleBranchIds()->all()))
            ->when(! $user->is_active, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->sum('amount_minor');

        return Money::toDecimal((int) $total);
    }
}
