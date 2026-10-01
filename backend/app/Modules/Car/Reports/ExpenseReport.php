<?php

namespace App\Modules\Car\Reports;

use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarExpense;
use App\Modules\Car\Models\CarExpenseType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Active (non-reversed) car expenses for a period, a month or a single car, with a breakdown by
 * expense type. "context" names the selected car/type/month (only cars the user can access).
 */
class ExpenseReport extends Report
{
    protected function filterRules(): array
    {
        return [
            'branch_id' => ['sometimes', 'string', 'max:26'],
            'month' => ['sometimes', 'date_format:Y-m', 'prohibits:from,to'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'expense_type_id' => ['sometimes', 'string', 'max:26'],
            'car_id' => ['sometimes', 'string', 'max:26'],
        ];
    }

    protected function sortable(): array
    {
        return ['expense_date' => 'car_expenses.expense_date', 'amount' => 'car_expenses.amount_minor'];
    }

    protected function defaultSort(): string
    {
        return 'expense_date';
    }

    protected function tieBreaker(): string
    {
        return 'id';
    }

    public function exportColumns(array $result): array
    {
        // For a single-car report the car is named in the header, so its columns are omitted.
        $singleCar = $result['context']['car'] !== null;

        return array_values(array_filter([
            ['label' => 'Date', 'type' => 'date', 'value' => fn ($r) => $r['expense_date']],
            ['label' => 'Type', 'type' => 'text', 'value' => fn ($r) => $r['expense_type']['name']],
            ['label' => 'Description', 'type' => 'text', 'value' => fn ($r) => $r['description']],
            $singleCar ? null : ['label' => 'Car', 'type' => 'text', 'value' => fn ($r) => $r['car']['brand'].' '.$r['car']['model']],
            $singleCar ? null : ['label' => 'Chassis', 'type' => 'text', 'value' => fn ($r) => $r['car']['chassis_number']],
            $singleCar ? null : ['label' => 'Branch', 'type' => 'text', 'value' => fn ($r) => $r['branch']['code'] ?? null],
            ['label' => 'Amount', 'type' => 'money', 'value' => fn ($r) => $r['amount'], 'total' => fn ($t) => $t['total']],
        ]));
    }

    public function exportTitleSuffix(array $result): ?string
    {
        $c = $result['context'];
        $car = $c['car'] ? trim("{$c['car']['brand']} {$c['car']['model']}").' ('.($c['car']['registration_number'] ?? $c['car']['chassis_number']).')' : null;

        return collect([$car, $c['month'], $c['expense_type']['name'] ?? null])->filter()->implode(' — ') ?: null;
    }

    public function exportContext(array $result): array
    {
        $c = $result['context'];

        return array_filter([
            'Car' => $c['car'] ? trim("{$c['car']['brand']} {$c['car']['model']} {$c['car']['model_year']}").' · Chassis '.$c['car']['chassis_number']
                .($c['car']['registration_number'] ? ' · Reg. '.$c['car']['registration_number'] : '')
                .($c['car']['branch'] ? ' · Branch '.$c['car']['branch']['code'] : '') : null,
            'Expense type' => $c['expense_type']['name'] ?? null,
            'Period' => $c['from'] || $c['to'] ? ($c['from'] ?? '…').' to '.($c['to'] ?? '…') : null,
        ]);
    }

    public function exportSummaries(array $result): array
    {
        return [[
            'title' => 'Summary by expense type',
            'columns' => [
                ['label' => 'Expense type', 'type' => 'text', 'value' => fn ($r) => $r['expense_type']['name']],
                ['label' => 'Entries', 'type' => 'int', 'value' => fn ($r) => $r['entries']],
                ['label' => 'Total', 'type' => 'money', 'value' => fn ($r) => $r['total']],
            ],
            'rows' => $result['totals']['by_type'],
        ]];
    }

    public function run(Request $request): array
    {
        ['filters' => $f, 'sort' => $sort, 'direction' => $dir, 'per_page' => $perPage] = $this->input($request);

        // A month is shorthand for its first..last day.
        $month = isset($f['month']) ? CarbonImmutable::createFromFormat('!Y-m', $f['month']) : null;
        if ($month) {
            $f['from'] = $month->startOfMonth()->toDateString();
            $f['to'] = $month->endOfMonth()->toDateString();
        }

        $query = CarExpense::query()->active();
        $this->access->scope($query);
        $query->when($f['branch_id'] ?? null, fn (Builder $q, $id) => $q->where('car_expenses.branch_id', $id))
            ->when($f['from'] ?? null, fn (Builder $q, $d) => $q->where('expense_date', '>=', $d))
            ->when($f['to'] ?? null, fn (Builder $q, $d) => $q->where('expense_date', '<=', $d))
            ->when($f['expense_type_id'] ?? null, fn (Builder $q, $id) => $q->where('expense_type_id', $id))
            ->when($f['car_id'] ?? null, fn (Builder $q, $id) => $q->where('car_id', $id));

        $byType = (clone $query)->toBase()
            ->selectRaw('expense_type_id, count(*) AS entries, sum(amount_minor) AS total')
            ->groupBy('expense_type_id')->orderByRaw('sum(amount_minor) desc')->get();
        $typeNames = CarExpenseType::whereIn('id', $byType->pluck('expense_type_id'))->pluck('name', 'id');

        $totals = [
            'entries' => (int) $byType->sum('entries'),
            'total' => self::money((int) $byType->sum('total')),
            'by_type' => $byType->map(fn ($t) => [
                'expense_type' => ['id' => $t->expense_type_id, 'name' => $typeNames[$t->expense_type_id] ?? null],
                'entries' => (int) $t->entries,
                'total' => self::money((int) $t->total),
            ])->values()->all(),
        ];

        $page = $this->fetch($this->applySort($query->with(['expenseType:id,name', 'car:id,branch_id,brand,model,chassis_number', 'car.branch:id,code,name']), $sort, $dir), $perPage);

        $car = isset($f['car_id'])
            ? Car::query()->accessibleBy($this->access->user)->with('branch:id,code,name')->find($f['car_id'])
            : null;
        $context = [
            'car' => $car ? [
                'id' => $car->id, 'brand' => $car->brand, 'model' => $car->model, 'model_year' => $car->model_year,
                'chassis_number' => $car->chassis_number, 'registration_number' => $car->registration_number,
                'branch' => $car->branch?->only('id', 'code', 'name'),
            ] : null,
            'expense_type' => isset($f['expense_type_id']) ? CarExpenseType::find($f['expense_type_id'])?->only('id', 'name') : null,
            'month' => $month?->format('F Y'),
            'from' => $f['from'] ?? null,
            'to' => $f['to'] ?? null,
        ];

        return self::paginated($page, fn (CarExpense $e) => [
            'id' => $e->id,
            'expense_date' => $e->expense_date->toDateString(),
            'expense_type' => $e->expenseType->only('id', 'name'),
            'description' => $e->description,
            'amount' => self::money($e->amount_minor),
            'car' => $e->car->only('id', 'brand', 'model', 'chassis_number'),
            'branch' => $e->car->branch?->only('id', 'code', 'name'),
        ], $totals, ['context' => $context]);
    }
}
