<?php

namespace App\Modules\Restaurant\Reports;

use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Services\FoodSaleQuery;
use App\Modules\Restaurant\Support\PaymentFormulas;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Food sales report: active (non-reversed) sales by sale time, as rows or per day.
 * Total = sale totals; paid = active payments against those sales (to date); due = total − paid.
 */
class SalesReport extends RestaurantReport
{
    public function __construct(private readonly FoodSaleQuery $sales) {}

    public function permission(): string
    {
        return 'restaurant.sale.view';
    }

    public function exportTitle(string $groupBy): string
    {
        return $groupBy === 'day' ? 'Food sales — daily totals' : 'Food sales';
    }

    public function filterLabels(): array
    {
        return ['payment_status' => 'Payment status', 'customer_id' => 'Customer'];
    }

    public function exportColumns(string $groupBy): array
    {
        $figures = [
            ['label' => 'Total', 'type' => 'money', 'value' => fn ($r) => $r['total'], 'total' => fn ($t) => $t['total']],
            ['label' => 'Received', 'type' => 'money', 'value' => fn ($r) => $r['paid'], 'total' => fn ($t) => $t['paid']],
            ['label' => 'Due', 'type' => 'money', 'value' => fn ($r) => $r['due'], 'total' => fn ($t) => $t['due']],
        ];

        if ($groupBy === 'day') {
            return [
                ['label' => 'Date', 'type' => 'date', 'value' => fn ($r) => $r['date']],
                ['label' => 'Sales', 'type' => 'int', 'value' => fn ($r) => $r['count'], 'total' => fn ($t) => $t['count']],
                ...$figures,
            ];
        }

        return [
            ['label' => 'Sale no.', 'type' => 'text', 'value' => fn ($r) => $r['sale_no']],
            ['label' => 'Sale time', 'type' => 'date', 'value' => fn ($r) => Carbon::parse($r['sold_at'])->format('Y-m-d H:i')],
            ['label' => 'Branch', 'type' => 'text', 'value' => fn ($r) => $r['branch']['code']],
            ['label' => 'Customer', 'type' => 'text', 'value' => fn ($r) => $r['customer'] ?? 'Walk-in'],
            ['label' => 'Items', 'type' => 'int', 'value' => fn ($r) => $r['items_count']],
            ...$figures,
            ['label' => 'Payment status', 'type' => 'text', 'value' => fn ($r) => $r['payment_status']],
        ];
    }

    protected function groupings(): array
    {
        return ['', 'day'];
    }

    protected function filterRules(): array
    {
        return [
            'customer_id' => ['sometimes', 'string', 'max:26'],
            'payment_status' => ['sometimes', Rule::in(['unpaid', 'partial', 'paid'])],
        ];
    }

    protected function sortable(string $groupBy): array
    {
        $paid = FoodSale::paidSql();

        return $groupBy === 'day'
            // Aggregate expressions (PostgreSQL does not allow output aliases inside ORDER BY expressions).
            ? ['day' => 'day', 'count' => 'count(*)', 'total' => 'sum(total_minor)', 'paid' => 'sum(paid_minor)', 'due' => '(sum(total_minor) - sum(paid_minor))']
            : [
                'sold_at' => 'restaurant_sales.sold_at',
                'sale_no' => 'restaurant_sales.sale_no',
                'total' => 'restaurant_sales.total_minor',
                'paid' => $paid,
                'due' => "(restaurant_sales.total_minor - {$paid})",
            ];
    }

    protected function defaultSort(string $groupBy): string
    {
        return $groupBy === 'day' ? 'day' : 'sold_at';
    }

    public function run(User $user, Request $request): array
    {
        $input = $this->input($request);
        $filtered = $this->sales->filtered($user, ['state' => 'active'] + $input['filters']);
        $summary = $this->sales->summary($filtered);
        $totals = ['count' => $summary['count'], 'total' => $summary['total'], 'paid' => $summary['paid'], 'due' => $summary['due']];
        $order = "{$this->sortable($input['group_by'])[$input['sort']]} {$input['direction']}";

        if ($input['group_by'] === 'day') {
            // Per-sale figures first, then grouped by local calendar day (DB session uses the app time zone).
            $perSale = $filtered->clone()->toBase()
                ->select(DB::raw('restaurant_sales.sold_at::date AS day'), 'restaurant_sales.total_minor')
                ->selectRaw(FoodSale::paidSql().' AS paid_minor');
            $page = DB::query()->fromSub($perSale, 's')
                ->selectRaw("to_char(day, 'YYYY-MM-DD') AS day, count(*) AS sales, sum(total_minor) AS total, sum(paid_minor) AS paid")
                ->groupBy('day')
                ->orderByRaw($order)
                ->orderBy('day', 'desc');
            $page = $this->fetch($page, $input['per_page']);

            return self::paginated($page, fn ($r) => [
                'date' => $r->day,
                'count' => (int) $r->sales,
                'total' => self::money((int) $r->total),
                'paid' => self::money((int) $r->paid),
                'due' => self::money((int) $r->total - (int) $r->paid),
            ], $totals, $input);
        }

        $page = $filtered->clone()
            ->withPaid()
            ->with(['branch:id,name,code', 'customer:id,name'])
            ->withCount('items')
            ->orderByRaw($order)
            ->orderBy('restaurant_sales.id', 'desc');
        $page = $this->fetch($page, $input['per_page']);

        return self::paginated($page, fn (FoodSale $sale) => [
            'id' => $sale->id,
            'sale_no' => $sale->sale_no,
            'sold_at' => $sale->sold_at->toIso8601String(),
            'branch' => $sale->branch->only('id', 'code', 'name'),
            'customer' => $sale->customer?->name,
            'items_count' => $sale->items_count,
            'total' => self::money($sale->total_minor),
            'paid' => self::money($sale->paid_minor),
            'due' => self::money(PaymentFormulas::due($sale->total_minor, $sale->paid_minor)),
            'payment_status' => PaymentFormulas::status($sale->total_minor, $sale->paid_minor)->value,
        ], $totals, $input);
    }
}
