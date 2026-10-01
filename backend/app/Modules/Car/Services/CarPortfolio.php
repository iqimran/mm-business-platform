<?php

namespace App\Modules\Car\Services;

use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarDealerPayment;
use App\Modules\Car\Models\CarExpense;
use App\Modules\Car\Models\CarPartyPayment;
use App\Modules\Car\Models\CarPurchase;
use App\Modules\Car\Models\CarSale;
use App\Modules\Car\Support\FinancialFormulas;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard figures across the cars of the user's accessible branches.
 *
 * Aggregates sum the formula inputs in SQL and apply the same FinancialFormulas once.
 * The formulas are linear (and payments can never exceed the obligation), so this equals
 * the sum of the per-car values from CarFinancials — verified by tests.
 */
class CarPortfolio
{
    /**
     * @param  array{branch_id?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function summary(User $user, array $filters = []): array
    {
        $cars = Car::query()->accessibleBy($user)
            ->when($filters['branch_id'] ?? null, fn (Builder $q, string $id) => $q->where('branch_id', $id));
        $carIds = (clone $cars)->select('id');

        $can = fn (string ...$permissions) => collect($permissions)->every(fn ($p) => $user->hasPermission($p));

        $result = [
            'cars_by_status' => collect(CarStatus::cases())
                ->mapWithKeys(fn (CarStatus $s) => [$s->value => 0])
                ->merge((clone $cars)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n))
                ->all(),
            'stock' => null,
            'receivables' => null,
            'payables' => null,
            'sales' => null,
        ];

        if ($can('car.purchase.view', 'car.expense.view')) {
            // Investment tied up in unsold cars.
            $unsold = (clone $cars)->whereNotIn('status', [CarStatus::Sold, CarStatus::Completed])->select('id');
            $purchase = $this->sum(CarPurchase::query()->active()->whereIn('car_id', $unsold));
            $expenses = $this->sum(CarExpense::query()->active()->whereIn('car_id', $unsold));
            $result['stock'] = [
                'cars' => (clone $cars)->whereNotIn('status', [CarStatus::Sold, CarStatus::Completed])->count(),
                'purchase_cost' => Money::toDecimal($purchase),
                'expenses_total' => Money::toDecimal($expenses),
                'total_investment' => Money::toDecimal(FinancialFormulas::totalInvestment($purchase, $expenses)),
            ];
        }

        if ($can('car.sale.view')) {
            // Party Due across all active sales.
            $sales = CarSale::query()->active()->whereIn('car_id', $carIds);
            $amount = $this->sum(clone $sales);
            $received = $this->sum(CarPartyPayment::query()->active()->whereIn('sale_id', (clone $sales)->select('id')));
            $result['receivables'] = [
                'sale_amount' => Money::toDecimal($amount),
                'received' => Money::toDecimal($received),
                'party_due' => Money::toDecimal(FinancialFormulas::partyDue($amount, $received)),
            ];
        }

        if ($can('car.dealer_payment.view')) {
            // Dealer Payable across all active purchases.
            $purchases = CarPurchase::query()->active()->whereIn('car_id', $carIds);
            $amount = $this->sum(clone $purchases);
            $paid = $this->sum(CarDealerPayment::query()->active()->whereIn('purchase_id', (clone $purchases)->select('id')));
            $result['payables'] = [
                'purchase_amount' => Money::toDecimal($amount),
                'paid' => Money::toDecimal($paid),
                'dealer_payable' => Money::toDecimal(FinancialFormulas::dealerPayable($amount, $paid)),
            ];
        }

        if ($can('car.sale.view')) {
            // Sales in the period (by sale date) and, with cost permissions, their profit.
            $periodSales = CarSale::query()->active()->whereIn('car_id', $carIds)
                ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('sale_date', '>=', $d))
                ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('sale_date', '<=', $d));
            $saleTotal = $this->sum(clone $periodSales);
            $soldCars = (clone $periodSales)->select('car_id');

            $profit = null;
            if ($can('car.purchase.view', 'car.expense.view')) {
                $cost = $this->sum(CarPurchase::query()->active()->whereIn('car_id', $soldCars));
                $expenses = $this->sum(CarExpense::query()->active()->whereIn('car_id', $soldCars));
                $profit = [
                    'purchase_cost' => Money::toDecimal($cost),
                    'expenses_total' => Money::toDecimal($expenses),
                    'profit' => Money::toDecimal(FinancialFormulas::profit($saleTotal, $cost, $expenses)),
                ];
            }

            $result['sales'] = [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'count' => (clone $periodSales)->count(),
                'sale_total' => Money::toDecimal($saleTotal),
                'profit' => $profit,
            ];
        }

        return $result;
    }

    private function sum(Builder $query): int
    {
        return (int) $query->sum('amount_minor');
    }
}
