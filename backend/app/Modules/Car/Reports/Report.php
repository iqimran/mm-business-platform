<?php

namespace App\Modules\Car\Reports;

use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shared plumbing for car reports: validated filters, allow-listed server-side sorting
 * (only on columns the user may see), pagination and totals over the whole filtered set.
 */
abstract class Report
{
    /** When set, the report returns all rows (up to this limit) for export instead of one page. */
    private ?int $exportLimit = null;

    public function __construct(protected readonly ReportAccess $access) {}

    public function forExport(int $maxRows): static
    {
        $this->exportLimit = $maxRows;

        return $this;
    }

    /**
     * Export column definitions: label, type (text|money|int|date), value getter, optional total getter.
     * Columns the user may not see must be omitted.
     *
     * @return array<int, array{label: string, type: string, value: callable(array): mixed, total?: callable(array): mixed}>
     */
    abstract public function exportColumns(array $result): array;

    /** Optional suffix for the export title, e.g. the selected car or month. */
    public function exportTitleSuffix(array $result): ?string
    {
        return null;
    }

    /**
     * Extra header lines for exports (e.g. resolved names of filtered records).
     *
     * @return array<string, string>
     */
    public function exportContext(array $result): array
    {
        return [];
    }

    /**
     * Additional summary tables printed after the main table.
     *
     * @return array<int, array{title: string, columns: array<int, array{label: string, type: string, value: callable}>, rows: array<int, array>}>
     */
    public function exportSummaries(array $result): array
    {
        return [];
    }

    /**
     * One page normally; in export mode all rows (refusing more than the limit).
     */
    protected function fetch($query, int $perPage)
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

    /** @return array<string, array<int, mixed>> validation rules for report-specific filters */
    abstract protected function filterRules(): array;

    /** @return array<string, string|null> sort key => SQL column/expression, null when hidden for this user */
    abstract protected function sortable(): array;

    abstract protected function defaultSort(): string;

    /**
     * @return array{filters: array<string, mixed>, sort: string, direction: string, per_page: int}
     */
    protected function input(Request $request): array
    {
        $visibleSorts = array_keys(array_filter($this->sortable()));

        $validated = $request->validate($this->filterRules() + [
            'sort' => ['sometimes', 'string'],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $sort = $validated['sort'] ?? $this->defaultSort();
        if (! in_array($sort, $visibleSorts, true)) {
            throw ValidationException::withMessages(['sort' => 'Sorting by "'.$sort.'" is not available.']);
        }

        return [
            'filters' => array_diff_key($validated, array_flip(['sort', 'direction', 'per_page', 'page'])),
            'sort' => $sort,
            'direction' => $validated['direction'] ?? 'desc',
            'per_page' => ApiResponse::perPage($request),
        ];
    }

    protected function applySort(Builder $query, string $sort, string $direction): Builder
    {
        $column = $this->sortable()[$sort];

        // NULLs (e.g. unsold cars when sorting by profit) always go last; id keeps paging stable.
        return $query->orderByRaw("{$column} {$direction} NULLS LAST")->orderBy($query->qualifyColumn($this->tieBreaker()));
    }

    protected function tieBreaker(): string
    {
        return 'car_id';
    }

    /** Money for the response: decimal string, or null when hidden/not applicable. */
    protected static function money(?int $minor, bool $visible = true): ?string
    {
        return $visible && $minor !== null ? Money::toDecimal($minor) : null;
    }

    /**
     * @param  array<string, mixed>  $totals
     */
    protected static function paginated($paginator, callable $row, array $totals, array $meta = []): array
    {
        return [
            'items' => $paginator->getCollection()->map($row)->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'totals' => $totals,
        ] + $meta;
    }
}
