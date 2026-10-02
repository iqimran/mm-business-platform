<?php

namespace App\Modules\Restaurant\Reports;

use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Services\ExpenseQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Restaurant expenses report (active expenses only), grouped per day or per category.
 * The day × category matrix is the existing /restaurant/expense-summary endpoint.
 */
class ExpensesReport extends RestaurantReport
{
    public function __construct(private readonly ExpenseQuery $expenses) {}

    public function permission(): string
    {
        return 'restaurant.expense.view';
    }

    public function exportTitle(string $groupBy): string
    {
        return $groupBy === 'category' ? 'Restaurant expenses — by category' : 'Restaurant expenses — daily totals';
    }

    public function filterLabels(): array
    {
        return ['category_id' => 'Category', 'supplier_id' => 'Supplier'];
    }

    public function exportColumns(string $groupBy): array
    {
        return [
            $groupBy === 'category'
                ? ['label' => 'Category', 'type' => 'text', 'value' => fn ($r) => $r['category']]
                : ['label' => 'Date', 'type' => 'date', 'value' => fn ($r) => $r['date']],
            ['label' => 'Expenses', 'type' => 'int', 'value' => fn ($r) => $r['count'], 'total' => fn ($t) => $t['count']],
            ['label' => 'Total', 'type' => 'money', 'value' => fn ($r) => $r['total'], 'total' => fn ($t) => $t['total']],
        ];
    }

    protected function groupings(): array
    {
        return ['day', 'category'];
    }

    protected function filterRules(): array
    {
        return [
            'category_id' => ['sometimes', 'string', 'max:26'],
            'supplier_id' => ['sometimes', 'string', 'max:26'],
        ];
    }

    protected function sortable(string $groupBy): array
    {
        return $groupBy === 'category'
            ? ['category' => 'lower(c.name)', 'count' => 'expenses', 'total' => 'total']
            : ['day' => 'restaurant_expenses.expense_date', 'count' => 'expenses', 'total' => 'total'];
    }

    protected function defaultSort(string $groupBy): string
    {
        return $groupBy === 'category' ? 'total' : 'day';
    }

    public function run(User $user, Request $request): array
    {
        $input = $this->input($request);
        $input['group_by'] = $input['group_by'] ?: 'day';
        if (! array_key_exists($input['sort'], $this->sortable($input['group_by']))) {
            $input['sort'] = $this->defaultSort($input['group_by']);
        }

        $filtered = $this->expenses->filtered($user, ['state' => 'active'] + $input['filters']);
        $totals = $this->expenses->totals($filtered);
        $order = "{$this->sortable($input['group_by'])[$input['sort']]} {$input['direction']}";
        $base = $filtered->clone()->toBase();

        if ($input['group_by'] === 'category') {
            $page = $base
                ->join('restaurant_expense_categories as c', 'c.id', '=', 'restaurant_expenses.category_id')
                ->groupBy('c.id', 'c.name')
                ->select('c.id', 'c.name')
                ->selectRaw('count(*) AS expenses, sum(restaurant_expenses.amount_minor) AS total')
                ->orderByRaw($order)
                ->orderBy('c.name');
            $page = $this->fetch($page, $input['per_page']);

            return self::paginated($page, fn ($r) => [
                'category_id' => $r->id,
                'category' => $r->name,
                'count' => (int) $r->expenses,
                'total' => self::money((int) $r->total),
            ], $totals, $input);
        }

        $page = $base
            ->groupBy('restaurant_expenses.expense_date')
            ->select(DB::raw("to_char(restaurant_expenses.expense_date, 'YYYY-MM-DD') AS day"))
            ->selectRaw('count(*) AS expenses, sum(restaurant_expenses.amount_minor) AS total')
            ->orderByRaw($order)
            ->orderBy('restaurant_expenses.expense_date', 'desc');
        $page = $this->fetch($page, $input['per_page']);

        return self::paginated($page, fn ($r) => [
            'date' => $r->day,
            'count' => (int) $r->expenses,
            'total' => self::money((int) $r->total),
        ], $totals, $input);
    }
}
