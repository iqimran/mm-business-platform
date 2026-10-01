<?php

namespace App\Modules\Car\Reports;

use App\Modules\Car\Models\CarFinancialPosition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Base for reports over car_financial_positions (cars, sales, receivables, payables).
 * Rows are eager-loaded (branch, dealer, party) to avoid N+1 queries.
 */
abstract class PositionReport extends Report
{
    protected function positions(array $filters): Builder
    {
        $query = CarFinancialPosition::query()->with(['branch:id,code,name', 'dealer:id,name', 'party:id,name,phone']);
        $this->access->scope($query);

        return $query
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $id) => $q->where('branch_id', $id))
            ->when($filters['search'] ?? null, fn (Builder $q, $s) => $q->where(fn (Builder $w) => $w
                ->where('brand', 'ilike', "%{$s}%")
                ->orWhere('model', 'ilike', "%{$s}%")
                ->orWhere('chassis_number', 'ilike', '%'.strtoupper(preg_replace('/\s+/', '', $s)).'%')
                ->orWhere('registration_number', 'ilike', "%{$s}%")));
    }

    /**
     * Builds an export column; omitted (null) when the user may not see it.
     *
     * @return array<string, mixed>|null
     */
    protected static function col(string $label, string $type, callable $value, bool $visible = true, ?string $totalKey = null): ?array
    {
        if (! $visible) {
            return null;
        }

        return array_filter([
            'label' => $label,
            'type' => $type,
            'value' => $value,
            'total' => $totalKey ? fn (array $totals) => $totals[$totalKey] ?? null : null,
        ]);
    }

    /** @return array<int, array<string, mixed>|null> car identity columns */
    protected function carColumns(): array
    {
        return [
            self::col('Car', 'text', fn ($r) => trim($r['car']['brand'].' '.$r['car']['model'].' '.($r['car']['model_year'] ?? '')), true),
            self::col('Registration / chassis', 'text', fn ($r) => $r['car']['registration_number'] ?? $r['car']['chassis_number']),
            self::col('Branch', 'text', fn ($r) => $r['branch']['code'] ?? null),
        ];
    }

    /** @return array<string, array<int, mixed>> */
    protected function commonFilterRules(): array
    {
        return [
            'branch_id' => ['sometimes', 'string', 'max:26'],
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }

    /**
     * Totals over the full filtered set (one aggregate query), hidden per access.
     *
     * @return array<string, mixed>
     */
    protected function totals(Builder $query): array
    {
        $a = $this->access;
        $t = (clone $query)->setEagerLoads([])->reorder()->toBase()->selectRaw('
            count(*) AS cars,
            coalesce(sum(purchase_cost), 0) AS purchase_cost,
            coalesce(sum(expenses_total), 0) AS expenses_total,
            coalesce(sum(total_investment), 0) AS total_investment,
            count(sale_id) AS sold,
            coalesce(sum(sale_amount), 0) AS sale_amount,
            coalesce(sum(party_received), 0) AS party_received,
            coalesce(sum(party_due), 0) AS party_due,
            coalesce(sum(dealer_paid), 0) AS dealer_paid,
            coalesce(sum(dealer_payable), 0) AS dealer_payable,
            coalesce(sum(profit), 0) AS profit
        ')->first();

        return [
            'cars' => (int) $t->cars,
            'sold' => (int) $t->sold,
            'purchase_cost' => self::money((int) $t->purchase_cost, $a->purchase),
            'expenses_total' => self::money((int) $t->expenses_total, $a->expenses),
            'total_investment' => self::money((int) $t->total_investment, $a->costs),
            'sale_amount' => self::money((int) $t->sale_amount, $a->sale),
            'party_received' => self::money((int) $t->party_received, $a->sale),
            'party_due' => self::money((int) $t->party_due, $a->sale),
            'dealer_paid' => self::money((int) $t->dealer_paid, $a->dealer),
            'dealer_payable' => self::money((int) $t->dealer_payable, $a->dealer),
            'profit' => self::money((int) $t->profit, $a->profit),
        ];
    }

    /** @return array<string, mixed> */
    protected function row(CarFinancialPosition $p): array
    {
        $a = $this->access;
        $unsold = $p->sale_id === null;

        return [
            'car' => [
                'id' => $p->car_id,
                'brand' => $p->brand,
                'model' => $p->model,
                'model_year' => $p->model_year,
                'chassis_number' => $p->chassis_number,
                'registration_number' => $p->registration_number,
            ],
            'branch' => $p->branch?->only('id', 'code', 'name'),
            'status' => $p->status->value,
            'dealer' => $a->purchase ? $p->dealer?->only('id', 'name') : null,
            'purchase_date' => $a->purchase ? $p->purchase_date?->toDateString() : null,
            'days_in_stock' => $unsold && $p->purchase_date && $a->purchase
                ? (int) $p->purchase_date->toImmutable()->diffInDays(CarbonImmutable::today()) : null,
            'purchase_cost' => self::money($p->purchase_cost, $a->purchase),
            'expenses_total' => self::money($p->expenses_total, $a->expenses),
            'total_investment' => self::money($p->total_investment, $a->costs),
            'party' => $a->sale ? $p->party?->only('id', 'name', 'phone') : null,
            'sale_date' => $a->sale ? $p->sale_date?->toDateString() : null,
            'sale_amount' => self::money($p->sale_amount, $a->sale),
            'party_received' => self::money($p->party_received, $a->sale),
            'party_due' => self::money($p->party_due, $a->sale),
            'days_outstanding' => $a->sale && $p->sale_date && $p->party_due > 0
                ? (int) $p->sale_date->toImmutable()->diffInDays(CarbonImmutable::today()) : null,
            'dealer_paid' => self::money($p->dealer_paid, $a->dealer),
            'dealer_payable' => self::money($p->dealer_payable, $a->dealer),
            'profit' => self::money($p->profit, $a->profit),
        ];
    }
}
