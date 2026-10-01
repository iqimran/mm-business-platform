<?php

namespace App\Modules\Car\Reports;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which figures a user may see in reports. Mirrors the per-car rules:
 * costs need purchase + expense views, sale/party figures need sale view,
 * dealer figures need dealer-payment view, profit needs sale + purchase + expense views.
 */
final class ReportAccess
{
    public readonly bool $purchase;

    public readonly bool $expenses;

    public readonly bool $costs;

    public readonly bool $sale;

    public readonly bool $dealer;

    public readonly bool $profit;

    public function __construct(public readonly User $user)
    {
        $this->purchase = $user->hasPermission('car.purchase.view');
        $this->expenses = $user->hasPermission('car.expense.view');
        $this->costs = $this->purchase && $this->expenses;
        $this->sale = $user->hasPermission('car.sale.view');
        $this->dealer = $user->hasPermission('car.dealer_payment.view');
        $this->profit = $this->sale && $this->costs;
    }

    /** Branch isolation: only rows of branches the user can access. */
    public function scope(Builder $query, string $column = 'branch_id'): Builder
    {
        return $query->whereIn($query->qualifyColumn($column), $this->user->accessibleBranchIds());
    }
}
