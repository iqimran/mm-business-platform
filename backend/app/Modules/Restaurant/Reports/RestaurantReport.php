<?php

namespace App\Modules\Restaurant\Reports;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shared plumbing for restaurant reports: validated filters (single date or range), allow-listed
 * server-side sorting, pagination and totals over the whole filtered set.
 * Figures come from the restaurant query services, so reports match the operational screens.
 */
abstract class RestaurantReport
{
    /** When set, the report returns all rows (up to this limit) for export instead of one page. */
    private ?int $exportLimit = null;

    public function forExport(int $maxRows): static
    {
        $this->exportLimit = $maxRows;

        return $this;
    }

    /** Export title, e.g. "Food sales — daily totals". */
    abstract public function exportTitle(string $groupBy): string;

    /**
     * Export columns for the current grouping: label, type (text|money|int|date), value getter, optional total getter.
     *
     * @return list<array{label: string, type: string, value: callable(array): mixed, total?: callable(array): mixed}>
     */
    abstract public function exportColumns(string $groupBy): array;

    /** Human-readable filter labels for export headers (filter key => label). */
    public function filterLabels(): array
    {
        return [];
    }

    /** Permission for the reported area, in addition to restaurant.report.view. */
    abstract public function permission(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function run(User $user, Request $request): array;

    /** @return array<string, array<int, mixed>> */
    abstract protected function filterRules(): array;

    /** @return array<string, string> sort key => SQL column/expression */
    abstract protected function sortable(string $groupBy): array;

    abstract protected function defaultSort(string $groupBy): string;

    /** @return list<string> allowed group_by values ("" = individual rows) */
    protected function groupings(): array
    {
        return [''];
    }

    /**
     * @return array{filters: array<string, mixed>, group_by: string, sort: string, direction: string, per_page: int}
     */
    protected function input(Request $request): array
    {
        $validated = $request->validate($this->filterRules() + [
            'branch_id' => ['sometimes', 'string', 'max:26'],
            'date' => ['sometimes', 'date_format:Y-m-d', 'prohibits:date_from,date_to'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'group_by' => ['sometimes', Rule::in(array_values(array_filter($this->groupings())))],
            'sort' => ['sometimes', 'string'],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $groupBy = $validated['group_by'] ?? '';
        $sort = $validated['sort'] ?? $this->defaultSort($groupBy);
        if (! array_key_exists($sort, $this->sortable($groupBy))) {
            throw ValidationException::withMessages(['sort' => 'Sorting by "'.$sort.'" is not available.']);
        }

        $filters = array_diff_key($validated, array_flip(['group_by', 'sort', 'direction', 'per_page', 'page']));
        // A single day is a one-day range for the query services.
        if (isset($filters['date'])) {
            $filters['date_from'] = $filters['date_to'] = $filters['date'];
            unset($filters['date']);
        }

        return [
            'filters' => $filters,
            'group_by' => $groupBy,
            'sort' => $sort,
            'direction' => $validated['direction'] ?? 'desc',
            'per_page' => ApiResponse::perPage($request),
        ];
    }

    /**
     * One page normally; in export mode all rows (refusing more than the limit).
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     */
    protected function fetch($query, int $perPage): LengthAwarePaginator
    {
        if ($this->exportLimit === null) {
            return $query->paginate($perPage);
        }

        $all = $query->paginate($this->exportLimit, ['*'], 'page', 1);
        if ($all->total() > $this->exportLimit) {
            throw ValidationException::withMessages([
                'format' => "This report has {$all->total()} rows; the export limit is {$this->exportLimit}. Narrow the filters and try again.",
            ]);
        }

        return $all;
    }

    protected static function money(int $minor): string
    {
        return Money::toDecimal($minor);
    }

    /**
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $input
     */
    protected static function paginated(LengthAwarePaginator $page, callable $row, array $totals, array $input): array
    {
        return [
            'items' => $page->getCollection()->map($row)->values()->all(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'totals' => $totals,
            'filters' => $input['filters'],
            'group_by' => $input['group_by'] ?: null,
            'sort' => $input['sort'],
            'direction' => $input['direction'],
        ];
    }
}
