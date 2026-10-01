<?php

namespace Tests\Feature\Car;

use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarExpenseType;
use App\Modules\Identity\Models\User;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Feature\Car\Concerns\BuildsCarRecords;

/**
 * Per-car and per-month expense reports, on screen and exported.
 */
class ExpenseReportFiltersTest extends CarTestCase
{
    use BuildsCarRecords;

    private Car $corolla;

    private Car $civic;

    private Car $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $paint = CarExpenseType::factory()->create(['name' => 'Paint']);
        $tyres = CarExpenseType::factory()->create(['name' => 'Tyres']);

        $this->corolla = $this->car();
        $this->corolla->update(['brand' => 'Toyota', 'model' => 'Corolla', 'registration_number' => 'DHAKA GA 11-1111']);
        $this->expense($this->corolla, '30000', '2026-08-31', $paint);  // August (boundary)
        $this->expense($this->corolla, '25000', '2026-09-01', $paint);  // September first day
        $this->expense($this->corolla, '8000', '2026-09-30', $tyres);   // September last day
        $this->reverse($this->expense($this->corolla, '99999', '2026-09-15', $paint)); // reversed: never counted

        $this->civic = $this->car();
        $this->civic->update(['brand' => 'Honda', 'model' => 'Civic']);
        $this->expense($this->civic, '12000', '2026-09-10', $tyres);
        $this->expense($this->civic, '5000', '2026-10-01', $paint);     // October (boundary)

        $this->foreign = $this->car($this->branchB);
        $this->foreign->update(['brand' => 'Mazda']);
        $this->expense($this->foreign, '70000', '2026-09-12', $paint);
    }

    private function reporter(): User
    {
        return $this->userWith(['car.report.view', 'car.view', 'car.expense.view'], [$this->branchA]);
    }

    private function report(string $query): array
    {
        return $this->actingAs($this->reporter())->getJson("/api/v1/car-reports/expenses?{$query}")->assertOk()->json('data');
    }

    public function test_month_filter_includes_whole_month_only(): void
    {
        $data = $this->report('month=2026-09&sort=expense_date&direction=asc');

        $this->assertSame(['2026-09-01', '2026-09-10', '2026-09-30'], array_column($data['items'], 'expense_date'));
        $this->assertSame('45000.00', $data['totals']['total']);
        $this->assertSame(['month' => 'September 2026', 'from' => '2026-09-01', 'to' => '2026-09-30'],
            array_intersect_key($data['context'], array_flip(['month', 'from', 'to'])));
        $this->assertEqualsCanonicalizing(
            [['Paint', '25000.00'], ['Tyres', '20000.00']],
            array_map(fn ($t) => [$t['expense_type']['name'], $t['total']], $data['totals']['by_type']),
        );
    }

    public function test_month_cannot_be_combined_with_a_date_range(): void
    {
        $this->actingAs($this->reporter())->getJson('/api/v1/car-reports/expenses?month=2026-09&from=2026-09-01')
            ->assertJsonValidationErrors('month');
        $this->actingAs($this->reporter())->getJson('/api/v1/car-reports/expenses?month=2026-13')
            ->assertJsonValidationErrors('month');
    }

    public function test_single_car_report_lists_all_its_expenses(): void
    {
        $data = $this->report("car_id={$this->corolla->id}");

        $this->assertCount(3, $data['items']);
        $this->assertSame('63000.00', $data['totals']['total']);
        $this->assertSame('Corolla', $data['context']['car']['model']);
        $this->assertSame('A', $data['items'][0]['branch']['code']);

        $this->assertSame('25000.00', $this->report("car_id={$this->corolla->id}&month=2026-09&expense_type_id=".
            CarExpenseType::where('name', 'Paint')->value('id'))['totals']['total']);
    }

    public function test_car_from_another_branch_is_not_revealed(): void
    {
        $data = $this->report("car_id={$this->foreign->id}");

        $this->assertCount(0, $data['items']);
        $this->assertNull($data['context']['car']);
        $this->assertSame('0.00', $data['totals']['total']);
    }

    /** @return array<int, array<int, mixed>> */
    private function sheet(TestResponse $response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, file_get_contents($response->baseResponse->getFile()->getPathname()));
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                // The reader pads rows to the widest row; drop trailing empty cells.
                $cells = $row->toArray();
                while ($cells !== [] && end($cells) === '') {
                    array_pop($cells);
                }
                $rows[] = $cells;
            }
            break;
        }
        $reader->close();
        unlink($path);

        return $rows;
    }

    public function test_car_export_is_titled_by_car_and_includes_type_summary(): void
    {
        $rows = $this->sheet($this->actingAs($this->reporter())
            ->get("/api/v1/car-reports/expenses/export?format=xlsx&car_id={$this->corolla->id}")->assertOk());

        $this->assertSame('Car expenses — Toyota Corolla (DHAKA GA 11-1111)', $rows[0][0]);
        $this->assertContains('Car', array_column($rows, 0));
        $header = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'Date');
        $this->assertSame(['Date', 'Type', 'Description', 'Amount'], $header);

        $summaryAt = collect($rows)->search(fn ($r) => ($r[0] ?? null) === 'Summary by expense type');
        $this->assertNotFalse($summaryAt);
        $this->assertSame(['Expense type', 'Entries', 'Total'], $rows[$summaryAt + 1]);
        $this->assertEqualsCanonicalizing([['Paint', 2, 55000.0], ['Tyres', 1, 8000.0]],
            array_map(fn ($r) => [$r[0], (int) $r[1], (float) $r[2]], array_slice($rows, $summaryAt + 2, 2)));
    }

    public function test_monthly_export_is_titled_by_month_with_all_cars(): void
    {
        $rows = $this->sheet($this->actingAs($this->reporter())
            ->get('/api/v1/car-reports/expenses/export?format=xlsx&month=2026-09')->assertOk());

        $this->assertSame('Car expenses — September 2026', $rows[0][0]);
        $header = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'Date');
        $this->assertContains('Car', $header);
        $this->assertStringNotContainsString('Mazda', json_encode($rows));

        $total = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'Total');
        $this->assertSame(45000.0, (float) end($total));

        $this->actingAs($this->reporter())->get('/api/v1/car-reports/expenses/export?format=pdf&month=2026-09')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
