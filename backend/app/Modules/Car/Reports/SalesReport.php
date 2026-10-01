<?php

namespace App\Modules\Car\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sales and profit: active sales in a period (by sale date) with price, cost, profit and collection.
 */
class SalesReport extends PositionReport
{
    protected function filterRules(): array
    {
        return $this->commonFilterRules() + [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'party_id' => ['sometimes', 'string', 'max:26'],
            'settled' => ['sometimes', Rule::in(['yes', 'no'])],
        ];
    }

    protected function sortable(): array
    {
        return [
            'sale_date' => 'sale_date',
            'sale_amount' => 'sale_amount',
            'party_due' => 'party_due',
            'profit' => $this->access->profit ? 'profit' : null,
            'brand' => 'brand',
        ];
    }

    protected function defaultSort(): string
    {
        return 'sale_date';
    }

    public function exportColumns(array $result): array
    {
        $a = $this->access;

        return array_values(array_filter([
            self::col('Sale date', 'date', fn ($r) => $r['sale_date']),
            ...$this->carColumns(),
            self::col('Party', 'text', fn ($r) => $r['party']['name'] ?? null),
            self::col('Sale amount', 'money', fn ($r) => $r['sale_amount'], true, 'sale_amount'),
            self::col('Purchase cost', 'money', fn ($r) => $r['purchase_cost'], $a->purchase, 'purchase_cost'),
            self::col('Expenses', 'money', fn ($r) => $r['expenses_total'], $a->expenses, 'expenses_total'),
            self::col('Total investment', 'money', fn ($r) => $r['total_investment'], $a->costs, 'total_investment'),
            self::col('Profit', 'money', fn ($r) => $r['profit'], $a->profit, 'profit'),
            self::col('Received', 'money', fn ($r) => $r['party_received'], true, 'party_received'),
            self::col('Party due', 'money', fn ($r) => $r['party_due'], true, 'party_due'),
        ]));
    }

    public function run(Request $request): array
    {
        ['filters' => $f, 'sort' => $sort, 'direction' => $dir, 'per_page' => $perPage] = $this->input($request);

        $query = $this->positions($f)
            ->whereNotNull('sale_id')
            ->when($f['from'] ?? null, fn (Builder $q, $d) => $q->where('sale_date', '>=', $d))
            ->when($f['to'] ?? null, fn (Builder $q, $d) => $q->where('sale_date', '<=', $d))
            ->when($f['party_id'] ?? null, fn (Builder $q, $id) => $q->where('party_id', $id))
            ->when(($f['settled'] ?? null) === 'yes', fn (Builder $q) => $q->where('party_due', 0))
            ->when(($f['settled'] ?? null) === 'no', fn (Builder $q) => $q->where('party_due', '>', 0));

        $totals = $this->totals($query);
        $page = $this->fetch($this->applySort($query, $sort, $dir), $perPage);

        return self::paginated($page, fn ($p) => $this->row($p), $totals);
    }
}
