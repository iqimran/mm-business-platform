<?php

namespace App\Modules\Car\Reports;

use App\Modules\Car\Enums\CarStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Inventory and sold/unsold register: every accessible car with its financial position.
 * state=unsold is the inventory (stock) report.
 */
class CarRegisterReport extends PositionReport
{
    protected function filterRules(): array
    {
        return $this->commonFilterRules() + [
            'state' => ['sometimes', Rule::in(['all', 'unsold', 'sold'])],
            'status' => ['sometimes', Rule::enum(CarStatus::class)],
            'dealer_id' => ['sometimes', 'string', 'max:26'],
            'purchased_from' => ['sometimes', 'date_format:Y-m-d'],
            'purchased_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:purchased_from'],
        ];
    }

    protected function sortable(): array
    {
        $a = $this->access;

        return [
            'brand' => 'brand',
            'status' => 'status',
            'purchase_date' => $a->purchase ? 'purchase_date' : null,
            'days_in_stock' => $a->purchase ? '(CASE WHEN sale_id IS NULL THEN current_date - purchase_date END)' : null,
            'total_investment' => $a->costs ? 'total_investment' : null,
            'sale_date' => $a->sale ? 'sale_date' : null,
            'profit' => $a->profit ? 'profit' : null,
        ];
    }

    protected function defaultSort(): string
    {
        return 'brand';
    }

    public function exportColumns(array $result): array
    {
        $a = $this->access;

        return array_values(array_filter([
            ...$this->carColumns(),
            self::col('Status', 'text', fn ($r) => $r['status']),
            self::col('Dealer', 'text', fn ($r) => $r['dealer']['name'] ?? null, $a->purchase),
            self::col('Purchase date', 'date', fn ($r) => $r['purchase_date'], $a->purchase),
            self::col('Days in stock', 'int', fn ($r) => $r['days_in_stock'], $a->purchase),
            self::col('Purchase cost', 'money', fn ($r) => $r['purchase_cost'], $a->purchase, 'purchase_cost'),
            self::col('Expenses', 'money', fn ($r) => $r['expenses_total'], $a->expenses, 'expenses_total'),
            self::col('Total investment', 'money', fn ($r) => $r['total_investment'], $a->costs, 'total_investment'),
            self::col('Party', 'text', fn ($r) => $r['party']['name'] ?? null, $a->sale),
            self::col('Sale date', 'date', fn ($r) => $r['sale_date'], $a->sale),
            self::col('Sale amount', 'money', fn ($r) => $r['sale_amount'], $a->sale, 'sale_amount'),
            self::col('Received', 'money', fn ($r) => $r['party_received'], $a->sale, 'party_received'),
            self::col('Party due', 'money', fn ($r) => $r['party_due'], $a->sale, 'party_due'),
            self::col('Dealer paid', 'money', fn ($r) => $r['dealer_paid'], $a->dealer, 'dealer_paid'),
            self::col('Dealer payable', 'money', fn ($r) => $r['dealer_payable'], $a->dealer, 'dealer_payable'),
            self::col('Profit', 'money', fn ($r) => $r['profit'], $a->profit, 'profit'),
        ]));
    }

    public function run(Request $request): array
    {
        ['filters' => $f, 'sort' => $sort, 'direction' => $dir, 'per_page' => $perPage] = $this->input($request);

        $query = $this->positions($f)
            ->when(($f['state'] ?? 'all') === 'unsold', fn (Builder $q) => $q->whereNull('sale_id'))
            ->when(($f['state'] ?? 'all') === 'sold', fn (Builder $q) => $q->whereNotNull('sale_id'))
            ->when($f['status'] ?? null, fn (Builder $q, $s) => $q->where('status', $s))
            ->when($this->access->purchase ? ($f['dealer_id'] ?? null) : null, fn (Builder $q, $id) => $q->where('dealer_id', $id))
            ->when($this->access->purchase ? ($f['purchased_from'] ?? null) : null, fn (Builder $q, $d) => $q->where('purchase_date', '>=', $d))
            ->when($this->access->purchase ? ($f['purchased_to'] ?? null) : null, fn (Builder $q, $d) => $q->where('purchase_date', '<=', $d));

        $totals = $this->totals($query);
        $page = $this->fetch($this->applySort($query, $sort, $dir), $perPage);

        return self::paginated($page, fn ($p) => $this->row($p), $totals);
    }
}
