<?php

namespace App\Modules\Car\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Branch\Models\Branch;
use App\Modules\Car\Reports\BranchReport;
use App\Modules\Car\Reports\CarRegisterReport;
use App\Modules\Car\Reports\ExpenseReport;
use App\Modules\Car\Reports\OutstandingReport;
use App\Modules\Car\Reports\Report;
use App\Modules\Car\Reports\ReportAccess;
use App\Modules\Car\Reports\ReportExporter;
use App\Modules\Car\Reports\SalesReport;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Car reports. Every report needs car.report.view plus the view permission of its data;
 * rows are limited to accessible branches and hidden figures are returned as null
 * (and omitted from exports).
 */
class CarReportController extends Controller
{
    /** name => [title, data permissions] */
    private const REPORTS = [
        'cars' => ['Car register', ['car.view']],
        'sales' => ['Sales & profit', ['car.sale.view']],
        'receivables' => ['Receivables (party due)', ['car.sale.view']],
        'payables' => ['Payables (dealer payable)', ['car.dealer_payment.view']],
        'expenses' => ['Car expenses', ['car.expense.view']],
        'branches' => ['Branch comparison', ['car.view']],
    ];

    /** Filters echoed in export headers (sort/paging excluded). */
    private const FILTER_LABELS = [
        'state' => 'Cars', 'status' => 'Status', 'month' => 'Month', 'from' => 'From', 'to' => 'To', 'settled' => 'Settled',
        'group' => 'Grouped by', 'search' => 'Search', 'older_than_days' => 'Older than (days)',
        'purchased_from' => 'Purchased from', 'purchased_to' => 'Purchased to',
    ];

    public function cars(Request $request): JsonResponse
    {
        return $this->show($request, 'cars');
    }

    public function sales(Request $request): JsonResponse
    {
        return $this->show($request, 'sales');
    }

    public function receivables(Request $request): JsonResponse
    {
        return $this->show($request, 'receivables');
    }

    public function payables(Request $request): JsonResponse
    {
        return $this->show($request, 'payables');
    }

    public function expenses(Request $request): JsonResponse
    {
        return $this->show($request, 'expenses');
    }

    public function branches(Request $request): JsonResponse
    {
        return $this->show($request, 'branches');
    }

    /**
     * GET /car-reports/{report}/export?format=xlsx|pdf plus the report's own filters and sort.
     */
    public function export(Request $request, string $report, ReportExporter $exporter, AuditLogger $audit): Response
    {
        $format = $request->validate(['format' => ['required', Rule::in(['xlsx', 'pdf'])]])['format'];
        $instance = $this->report($request, $report)->forExport(ReportExporter::$limits[$format]);

        $result = $instance->run($request);
        $columns = $instance->exportColumns($result);
        $suffix = $instance->exportTitleSuffix($result);
        $title = self::REPORTS[$report][0].($suffix ? ' — '.$suffix : '');
        $meta = $this->meta($request) + $instance->exportContext($result);
        $summaries = $instance->exportSummaries($result);
        $filename = 'car-report-'.$report.'-'.now()->format('Y-m-d-His').'.'.$format;

        $audit->record('car.report_exported', 'car_report', $report, $request->user()->id, newValues: [
            'format' => $format,
            'rows' => count($result['items']),
            'filters' => array_intersect_key($request->query(), self::FILTER_LABELS + ['branch_id' => true, 'car_id' => true, 'expense_type_id' => true, 'sort' => true, 'direction' => true]),
        ]);

        return $format === 'xlsx'
            ? $exporter->xlsx($title, $columns, $result, $meta, $filename, $summaries)
            : $exporter->pdf($title, $columns, $result, $meta, $filename, $summaries);
    }

    private function show(Request $request, string $name): JsonResponse
    {
        return ApiResponse::success($this->report($request, $name)->run($request));
    }

    /**
     * Authorizes and builds the report: car.report.view + the report's data permissions.
     */
    private function report(Request $request, string $name): Report
    {
        if (! isset(self::REPORTS[$name])) {
            throw new NotFoundHttpException;
        }

        $user = $request->user();
        foreach (['car.report.view', ...self::REPORTS[$name][1]] as $permission) {
            if (! $user->hasPermission($permission)) {
                throw new AuthorizationException;
            }
        }

        $access = new ReportAccess($user);

        return match ($name) {
            'cars' => new CarRegisterReport($access),
            'sales' => new SalesReport($access),
            'receivables' => new OutstandingReport($access, 'receivables'),
            'payables' => new OutstandingReport($access, 'payables'),
            'expenses' => new ExpenseReport($access),
            'branches' => new BranchReport($access),
        };
    }

    /**
     * @return array<string, string>
     */
    private function meta(Request $request): array
    {
        $filters = collect(self::FILTER_LABELS)
            ->filter(fn ($label, $key) => filled($request->query($key)))
            ->map(fn ($label, $key) => $label.': '.$request->query($key));

        if ($branchId = $request->query('branch_id')) {
            $branch = Branch::find($branchId);
            $filters->prepend('Branch: '.($branch ? $branch->code.' — '.$branch->name : $branchId));
        }

        return [
            'Generated' => now()->format('Y-m-d H:i').' by '.$request->user()->name,
            'Scope' => $request->user()->canAccessAllBranches() ? 'All branches' : 'Branches you can access',
            'Filters' => $filters->isEmpty() ? 'None' : $filters->implode(' · '),
        ];
    }
}
