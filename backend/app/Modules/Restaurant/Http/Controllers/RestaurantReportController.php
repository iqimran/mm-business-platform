<?php

namespace App\Modules\Restaurant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Services\BusinessProfiles;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Branch\Models\Branch;
use App\Modules\Restaurant\Reports\BookingsReport;
use App\Modules\Restaurant\Reports\ExpensesReport;
use App\Modules\Restaurant\Reports\FinancialSummary;
use App\Modules\Restaurant\Reports\ReportExporter;
use App\Modules\Restaurant\Reports\RestaurantReport;
use App\Modules\Restaurant\Reports\SalesReport;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restaurant reports: restaurant.report.view plus the view permission of the reported area.
 * All figures are branch-scoped to the user's accessible branches.
 */
class RestaurantReportController extends Controller
{
    private const REPORTS = [
        'sales' => SalesReport::class,
        'bookings' => BookingsReport::class,
        'expenses' => ExpensesReport::class,
    ];

    public function show(Request $request, string $report): JsonResponse
    {
        Gate::authorize('restaurant.report.view');

        /** @var RestaurantReport $instance */
        $instance = app(self::REPORTS[$report]);
        Gate::authorize($instance->permission());

        return ApiResponse::success($instance->run($request->user(), $request));
    }

    public function summary(Request $request, FinancialSummary $summary): JsonResponse
    {
        Gate::authorize('restaurant.report.view');

        return ApiResponse::success($summary->build($request->user(), $this->period($request)));
    }

    /**
     * Excel or PDF of a report with the same filters and sorting as on screen (all rows up to a limit).
     * Every export is audited.
     */
    public function export(Request $request, string $report, ReportExporter $exporter, FinancialSummary $summary, AuditLogger $audit, BusinessProfiles $profiles): Response
    {
        Gate::authorize('restaurant.report.view');
        $format = $request->validate(['format' => ['required', Rule::in(['xlsx', 'pdf'])]])['format'];

        if ($report === 'summary') {
            $period = $this->period($request);
            [$title, $columns, $result] = ['Restaurant financial summary', self::summaryColumns(), $this->summaryRows($summary->build($request->user(), $period))];
            $meta = $this->meta($request, $period['date_from'], $period['date_to'], $period['branch_id'] ?? null, []);
        } else {
            /** @var RestaurantReport $instance */
            $instance = app(self::REPORTS[$report]);
            Gate::authorize($instance->permission());
            $result = $instance->forExport(ReportExporter::$limits[$format])->run($request->user(), $request);
            $title = $instance->exportTitle($result['group_by'] ?? '');
            $columns = $instance->exportColumns($result['group_by'] ?? '');
            $filters = $result['filters'];
            $meta = $this->meta($request, $filters['date_from'] ?? null, $filters['date_to'] ?? null, $filters['branch_id'] ?? null,
                array_intersect_key($filters, $instance->filterLabels()), $instance->filterLabels());
        }

        $filename = 'restaurant-'.$report.'-'.now()->format('Y-m-d-His').'.'.$format;
        $audit->record('restaurant.report_exported', 'restaurant_report', $report, $request->user()->id, newValues: [
            'format' => $format,
            'rows' => count($result['items']),
            'filters' => array_intersect_key($request->query(), array_flip(['date', 'date_from', 'date_to', 'branch_id', 'group_by', 'sort', 'direction', 'status', 'payment_status', 'category_id', 'hall_id', 'customer_id', 'supplier_id'])),
        ]);

        $letterhead = $profiles->letterhead('restaurant');

        return $format === 'xlsx'
            ? $exporter->xlsx($title, $columns, $result, $meta, $filename, $letterhead)
            : $exporter->pdf($title, $columns, $result, $meta, $filename, $letterhead);
    }

    /** Financial summary export columns: one row per figure (revenue streams and expenses kept separate; no profit). */
    private static function summaryColumns(): array
    {
        return [
            ['label' => 'Area', 'type' => 'text', 'value' => fn (array $r) => $r['area']],
            ['label' => 'Figure', 'type' => 'text', 'value' => fn (array $r) => $r['figure']],
            ['label' => 'Count', 'type' => 'int', 'value' => fn (array $r) => $r['count']],
            ['label' => 'Amount', 'type' => 'money', 'value' => fn (array $r) => $r['amount']],
        ];
    }

    /**
     * @return array{items: list<array{area: string, figure: string, count: ?int, amount: ?string}>}
     */
    private function summaryRows(array $summary): array
    {
        $rows = [];
        foreach (['food_sales' => 'Food sales', 'hall_bookings' => 'Hall bookings'] as $key => $area) {
            if ($summary[$key] === null) {
                continue;
            }
            $s = $summary[$key];
            $rows[] = ['area' => $area, 'figure' => $key === 'food_sales' ? 'Sales' : 'Bookings', 'count' => $s['count'], 'amount' => null];
            $rows[] = ['area' => $area, 'figure' => 'Revenue', 'count' => null, 'amount' => $s['revenue']];
            $rows[] = ['area' => $area, 'figure' => 'Received', 'count' => null, 'amount' => $s['received']];
            $rows[] = ['area' => $area, 'figure' => 'Outstanding due', 'count' => null, 'amount' => $s['outstanding_due']];
            $rows[] = ['area' => $area, 'figure' => 'Collected in period', 'count' => null, 'amount' => $s['collected_in_period']];
        }
        if ($summary['expenses'] !== null) {
            $rows[] = ['area' => 'Restaurant expenses', 'figure' => 'Total expenses', 'count' => $summary['expenses']['count'], 'amount' => $summary['expenses']['total']];
        }

        return ['items' => $rows];
    }

    /**
     * @return array{date_from: string, date_to: string, branch_id: ?string}
     */
    private function period(Request $request): array
    {
        $period = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d', 'prohibits:date_from,date_to'],
            // No "sometimes": required_with must run when only one end of the range is given.
            'date_from' => ['required_with:date_to', 'date_format:Y-m-d'],
            'date_to' => ['required_with:date_from', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'branch_id' => ['sometimes', 'string', 'max:26'],
        ]);

        // Default period: the current month up to today.
        return [
            'date_from' => $period['date'] ?? $period['date_from'] ?? now()->startOfMonth()->toDateString(),
            'date_to' => $period['date'] ?? $period['date_to'] ?? now()->toDateString(),
            'branch_id' => $period['branch_id'] ?? null,
        ];
    }

    /**
     * Export header lines (never contains secrets).
     *
     * @return array<string, string>
     */
    private function meta(Request $request, ?string $from, ?string $to, ?string $branchId, array $filters, array $labels = []): array
    {
        $branch = $branchId ? Branch::find($branchId) : null;
        $meta = [
            'Period' => $from || $to ? ($from ?? '…').' to '.($to ?? '…') : 'All dates',
            'Branch' => $branch ? "{$branch->code} — {$branch->name}" : 'All accessible branches',
        ];
        foreach ($filters as $key => $value) {
            $meta[$labels[$key] ?? $key] = (string) $value;
        }

        return $meta + [
            'Generated' => now()->format('Y-m-d H:i').' by '.$request->user()->name,
            'Note' => 'Revenue and expenses are reported separately; no profit is calculated.',
        ];
    }
}
