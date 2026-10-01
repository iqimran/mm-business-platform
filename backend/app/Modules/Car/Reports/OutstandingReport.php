<?php

namespace App\Modules\Car\Reports;

use App\Modules\Car\Models\CarDealer;
use App\Modules\Car\Models\CarParty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Receivables (Party Due > 0) and payables (Dealer Payable > 0), per car or grouped by
 * counterparty. The two are separate reports and never netted against each other.
 */
class OutstandingReport extends PositionReport
{
    /** @param 'receivables'|'payables' $kind */
    public function __construct(ReportAccess $access, private readonly string $kind)
    {
        parent::__construct($access);
    }

    private function amountColumn(): string
    {
        return $this->kind === 'receivables' ? 'party_due' : 'dealer_payable';
    }

    private function counterparty(): string
    {
        return $this->kind === 'receivables' ? 'party_id' : 'dealer_id';
    }

    private function dateColumn(): string
    {
        return $this->kind === 'receivables' ? 'sale_date' : 'purchase_date';
    }

    protected function filterRules(): array
    {
        return $this->commonFilterRules() + [
            'group' => ['sometimes', Rule::in(['car', $this->kind === 'receivables' ? 'party' : 'dealer'])],
            $this->counterparty() => ['sometimes', 'string', 'max:26'],
            'older_than_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
        ];
    }

    protected function sortable(): array
    {
        return [
            'amount' => $this->amountColumn(),
            'date' => $this->dateColumn(),
            'brand' => 'brand',
        ];
    }

    protected function defaultSort(): string
    {
        return 'amount';
    }

    public function exportColumns(array $result): array
    {
        $receivables = $this->kind === 'receivables';
        $who = $receivables ? 'party' : 'dealer';
        [$origKey, $settledKey, $dueKey] = $receivables
            ? ['sale_amount', 'party_received', 'party_due']
            : ['purchase_cost', 'dealer_paid', 'dealer_payable'];

        if (isset($result['group'])) {
            return array_values(array_filter([
                self::col(ucfirst($who), 'text', fn ($r) => $r[$who]['name'] ?? null),
                self::col('Phone', 'text', fn ($r) => $r[$who]['phone'] ?? null),
                self::col('Cars', 'int', fn ($r) => $r['cars']),
                self::col($receivables ? 'Oldest sale' : 'Oldest purchase', 'date', fn ($r) => $r['oldest_date']),
                self::col($receivables ? 'Sales' : 'Purchases', 'money', fn ($r) => $r['original'], true, $origKey),
                self::col($receivables ? 'Received' : 'Paid', 'money', fn ($r) => $r['settled'], true, $settledKey),
                self::col($receivables ? 'Party due' : 'Dealer payable', 'money', fn ($r) => $r['outstanding'], true, $dueKey),
            ]));
        }

        return array_values(array_filter($receivables ? [
            self::col('Party', 'text', fn ($r) => $r['party']['name'] ?? null),
            self::col('Phone', 'text', fn ($r) => $r['party']['phone'] ?? null),
            ...$this->carColumns(),
            self::col('Sale date', 'date', fn ($r) => $r['sale_date']),
            self::col('Days outstanding', 'int', fn ($r) => $r['days_outstanding']),
            self::col('Sale amount', 'money', fn ($r) => $r['sale_amount'], true, 'sale_amount'),
            self::col('Received', 'money', fn ($r) => $r['party_received'], true, 'party_received'),
            self::col('Party due', 'money', fn ($r) => $r['party_due'], true, 'party_due'),
        ] : [
            self::col('Dealer', 'text', fn ($r) => $r['dealer']['name'] ?? null, $this->access->purchase),
            ...$this->carColumns(),
            self::col('Purchase date', 'date', fn ($r) => $r['purchase_date'], $this->access->purchase),
            self::col('Purchase amount', 'money', fn ($r) => $r['purchase_cost'], $this->access->purchase, 'purchase_cost'),
            self::col('Paid', 'money', fn ($r) => $r['dealer_paid'], true, 'dealer_paid'),
            self::col('Dealer payable', 'money', fn ($r) => $r['dealer_payable'], true, 'dealer_payable'),
        ]));
    }

    public function run(Request $request): array
    {
        ['filters' => $f, 'sort' => $sort, 'direction' => $dir, 'per_page' => $perPage] = $this->input($request);
        $amount = $this->amountColumn();

        $query = $this->positions($f)
            ->where($amount, '>', 0)
            ->when($f[$this->counterparty()] ?? null, fn (Builder $q, $id) => $q->where($this->counterparty(), $id))
            ->when(isset($f['older_than_days']), fn (Builder $q) => $q->where($this->dateColumn(), '<=', now()->subDays((int) $f['older_than_days'])->toDateString()));

        if (($f['group'] ?? 'car') !== 'car') {
            return $this->grouped($query, $sort, $dir, $perPage);
        }

        $totals = $this->totals($query);
        $page = $this->fetch($this->applySort($query, $sort, $dir), $perPage);

        return self::paginated($page, fn ($p) => $this->row($p), $totals);
    }

    /**
     * One row per party/dealer: number of cars, original amounts, paid and outstanding.
     */
    private function grouped(Builder $query, string $sort, string $dir, int $perPage): array
    {
        $key = $this->counterparty();
        [$original, $settled, $outstanding] = $this->kind === 'receivables'
            ? ['sale_amount', 'party_received', 'party_due']
            : ['dealer_purchase_amount', 'dealer_paid', 'dealer_payable'];

        $base = (clone $query)->setEagerLoads([])->reorder();
        $totals = $this->totals($query);

        $groupSort = match ($sort) {
            'date' => "min({$this->dateColumn()})",
            'brand' => 'count(*)',
            default => "sum({$outstanding})",
        };

        $page = $base->toBase()
            ->selectRaw("{$key} AS counterparty_id, count(*) AS cars, sum({$original}) AS original, sum({$settled}) AS settled, sum({$outstanding}) AS outstanding, min({$this->dateColumn()}) AS oldest")
            ->groupBy($key)
            ->orderByRaw("{$groupSort} {$dir}")
            ->orderBy($key);
        $page = $this->fetch($page, $perPage);

        $names = ($this->kind === 'receivables' ? CarParty::query() : CarDealer::query())
            ->whereIn('id', $page->getCollection()->pluck('counterparty_id'))
            ->get(['id', 'name', 'phone'])->keyBy('id');

        return self::paginated($page, fn ($g) => [
            $this->kind === 'receivables' ? 'party' : 'dealer' => $names->get($g->counterparty_id)?->only('id', 'name', 'phone'),
            'cars' => (int) $g->cars,
            'original' => self::money((int) $g->original),
            'settled' => self::money((int) $g->settled),
            'outstanding' => self::money((int) $g->outstanding),
            'oldest_date' => $g->oldest,
        ], $totals, ['group' => $this->kind === 'receivables' ? 'party' : 'dealer']);
    }
}
