<?php

namespace App\Modules\Car\Reports;

use App\Modules\Branch\Models\Branch;
use App\Modules\Car\Models\CarExpense;
use App\Modules\Car\Models\CarFinancialPosition;
use Illuminate\Http\Request;

/**
 * Branch comparison. Stock, receivables and payables are current positions;
 * sales, profit and expenses are for the selected period. One grouped query per figure set.
 */
class BranchReport extends Report
{
    protected function filterRules(): array
    {
        return [
            'branch_id' => ['sometimes', 'string', 'max:26'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    protected function sortable(): array
    {
        return ['code' => 'code'];
    }

    protected function defaultSort(): string
    {
        return 'code';
    }

    public function exportColumns(array $result): array
    {
        $a = $this->access;
        $col = fn (string $label, string $type, string $key, bool $visible = true) => $visible
            ? ['label' => $label, 'type' => $type, 'value' => fn ($r) => $r[$key], 'total' => fn ($t) => $t[$key]]
            : null;

        return array_values(array_filter([
            ['label' => 'Branch', 'type' => 'text', 'value' => fn ($r) => $r['branch']['code'].' — '.$r['branch']['name']],
            $col('In stock', 'int', 'stock_cars'),
            $col('Stock investment', 'money', 'stock_investment', $a->costs),
            $col('Expenses', 'money', 'expenses', $a->expenses),
            $col('Sold', 'int', 'sold_cars', $a->sale),
            $col('Sales', 'money', 'sales_total', $a->sale),
            $col('Profit', 'money', 'profit', $a->profit),
            $col('Party due', 'money', 'party_due', $a->sale),
            $col('Dealer payable', 'money', 'dealer_payable', $a->dealer),
        ]));
    }

    public function run(Request $request): array
    {
        ['filters' => $f] = $this->input($request);
        $a = $this->access;
        $from = $f['from'] ?? null;
        $to = $f['to'] ?? null;

        $branchIds = $a->user->accessibleBranchIds()
            ->when($f['branch_id'] ?? null, fn ($ids, $id) => $ids->intersect([$id]))->values();

        $positions = CarFinancialPosition::query()->whereIn('branch_id', $branchIds)->toBase()
            ->selectRaw('
                branch_id,
                count(*) FILTER (WHERE sale_id IS NULL) AS stock_cars,
                coalesce(sum(total_investment) FILTER (WHERE sale_id IS NULL), 0) AS stock_investment,
                coalesce(sum(party_due), 0) AS party_due,
                coalesce(sum(dealer_payable), 0) AS dealer_payable,
                count(*) FILTER (WHERE sale_id IS NOT NULL AND (?::date IS NULL OR sale_date >= ?::date) AND (?::date IS NULL OR sale_date <= ?::date)) AS sold_cars,
                coalesce(sum(sale_amount) FILTER (WHERE (?::date IS NULL OR sale_date >= ?::date) AND (?::date IS NULL OR sale_date <= ?::date)), 0) AS sales_total,
                coalesce(sum(profit) FILTER (WHERE (?::date IS NULL OR sale_date >= ?::date) AND (?::date IS NULL OR sale_date <= ?::date)), 0) AS profit
            ', [$from, $from, $to, $to, $from, $from, $to, $to, $from, $from, $to, $to])
            ->groupBy('branch_id')->get()->keyBy('branch_id');

        $expenses = CarExpense::query()->active()->whereIn('branch_id', $branchIds)
            ->when($from, fn ($q) => $q->where('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('expense_date', '<=', $to))
            ->toBase()->selectRaw('branch_id, coalesce(sum(amount_minor), 0) AS total')
            ->groupBy('branch_id')->pluck('total', 'branch_id');

        $keys = ['stock_investment' => $a->costs, 'expenses' => $a->expenses, 'sales_total' => $a->sale, 'profit' => $a->profit, 'party_due' => $a->sale, 'dealer_payable' => $a->dealer];

        // Minor-unit figures per branch; formatted (or hidden) only at the edge.
        $figures = Branch::whereIn('id', $branchIds)->orderBy('code')->get(['id', 'code', 'name'])
            ->map(function (Branch $b) use ($positions, $expenses) {
                $p = $positions->get($b->id);

                return [
                    'branch' => $b->only('id', 'code', 'name'),
                    'stock_cars' => (int) ($p->stock_cars ?? 0),
                    'sold_cars' => (int) ($p->sold_cars ?? 0),
                    'stock_investment' => (int) ($p->stock_investment ?? 0),
                    'expenses' => (int) ($expenses[$b->id] ?? 0),
                    'sales_total' => (int) ($p->sales_total ?? 0),
                    'profit' => (int) ($p->profit ?? 0),
                    'party_due' => (int) ($p->party_due ?? 0),
                    'dealer_payable' => (int) ($p->dealer_payable ?? 0),
                ];
            });

        $present = function (array $f) use ($keys, $a) {
            foreach ($keys as $key => $visible) {
                $f[$key] = self::money($f[$key], $visible);
            }
            $f['sold_cars'] = $a->sale ? $f['sold_cars'] : null;

            return $f;
        };

        $totals = ['stock_cars' => $figures->sum('stock_cars'), 'sold_cars' => $figures->sum('sold_cars')];
        foreach (array_keys($keys) as $key) {
            $totals[$key] = $figures->sum($key);
        }

        return [
            'items' => $figures->map($present)->values()->all(),
            'totals' => $present($totals),
            'period' => ['from' => $from, 'to' => $to],
        ];
    }
}
