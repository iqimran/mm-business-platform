<?php

namespace Tests\Feature\Car;

use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Reports\ReportExporter;
use App\Modules\Identity\Models\User;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Feature\Car\Concerns\BuildsCarRecords;

class CarReportExportTest extends CarTestCase
{
    use BuildsCarRecords;

    private const REPORTER = [
        'car.report.view', 'car.view', 'car.purchase.view', 'car.expense.view',
        'car.sale.view', 'car.payment.view', 'car.dealer_payment.view',
    ];

    private array $originalLimits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalLimits = ReportExporter::$limits;

        $sold = $this->car();
        $sold->update(['brand' => 'Toyota']);
        $this->purchase($sold, '700000');
        $this->expense($sold, '55000');
        $sale = $this->sale($sold, '850000', '2026-09-10');
        $this->partyPayment($sale, '500000');

        $stock = $this->car(null, CarStatus::InStock);
        $stock->update(['brand' => 'Nissan']);
        $this->purchase($stock, '400000.50');

        $foreign = $this->car($this->branchB);
        $foreign->update(['brand' => 'Mazda']);
        $this->purchase($foreign, '100000');
    }

    protected function tearDown(): void
    {
        ReportExporter::$limits = $this->originalLimits;
        parent::tearDown();
    }

    private function reporter(array $permissions = self::REPORTER): User
    {
        return $this->userWith($permissions, [$this->branchA]);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function sheetRows(TestResponse $response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent() ?: file_get_contents($response->baseResponse->getFile()->getPathname()));

        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();
        unlink($path);

        return $rows;
    }

    /** Rows after the header row (title, meta lines and a blank line come first). */
    private function table(array $rows, string $firstHeader): array
    {
        $headerIndex = collect($rows)->search(fn ($r) => ($r[0] ?? null) === $firstHeader);

        return ['header' => $rows[$headerIndex], 'body' => array_slice($rows, $headerIndex + 1)];
    }

    public function test_excel_export_has_header_rows_and_numeric_totals(): void
    {
        $response = $this->actingAs($this->reporter())
            ->get('/api/v1/car-reports/cars/export?format=xlsx&sort=brand&direction=asc')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('car-report-cars-', $response->headers->get('Content-Disposition'));

        ['header' => $header, 'body' => $body] = $this->table($this->sheetRows($response), 'Car');

        $profit = array_search('Profit', $header, true);
        $investment = array_search('Total investment', $header, true);
        $this->assertNotFalse($profit);

        $this->assertCount(3, $body); // Nissan, Toyota, Total
        $this->assertStringStartsWith('Nissan', $body[0][0]);
        $this->assertStringStartsWith('Toyota', $body[1][0]);
        $this->assertSame(95000.0, (float) $body[1][$profit]);
        $this->assertSame('Total', $body[2][0]);
        $this->assertSame(1155000.5, (float) $body[2][$investment]);
    }

    public function test_pdf_export_returns_a_pdf_document(): void
    {
        $response = $this->actingAs($this->reporter())->get('/api/v1/car-reports/sales/export?format=pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_export_applies_filters_and_branch_isolation(): void
    {
        $rows = $this->sheetRows($this->actingAs($this->reporter())->get('/api/v1/car-reports/cars/export?format=xlsx&state=unsold'));
        ['body' => $body] = $this->table($rows, 'Car');

        $this->assertCount(2, $body); // Nissan + Total
        $this->assertStringStartsWith('Nissan', $body[0][0]);
        $this->assertStringNotContainsString('Mazda', json_encode($rows));
        $this->assertContains(['Filters', 'Cars: unsold'], array_map(fn ($r) => array_slice($r, 0, 2), $rows));
    }

    public function test_hidden_figures_are_left_out_of_exports(): void
    {
        $rows = $this->sheetRows($this->actingAs($this->reporter(['car.report.view', 'car.view']))
            ->get('/api/v1/car-reports/cars/export?format=xlsx'));
        ['header' => $header] = $this->table($rows, 'Car');

        foreach (['Purchase cost', 'Total investment', 'Sale amount', 'Party due', 'Dealer payable', 'Profit'] as $hidden) {
            $this->assertNotContains($hidden, $header);
        }
        $this->assertContains('Status', $header);
    }

    public function test_every_report_exports_in_both_formats(): void
    {
        $user = $this->reporter();

        foreach (['cars', 'sales', 'receivables', 'payables', 'expenses', 'branches'] as $report) {
            foreach (['xlsx', 'pdf'] as $format) {
                $this->actingAs($user)->get("/api/v1/car-reports/{$report}/export?format={$format}")->assertOk();
            }
        }
        $this->actingAs($user)->get('/api/v1/car-reports/receivables/export?format=xlsx&group=party')->assertOk();
    }

    public function test_export_requires_permissions_and_valid_format(): void
    {
        $this->getJson('/api/v1/car-reports/cars/export?format=xlsx')->assertUnauthorized();
        $this->actingAs($this->reporter(['car.view']))->getJson('/api/v1/car-reports/cars/export?format=xlsx')->assertForbidden();
        $this->actingAs($this->reporter(['car.report.view', 'car.view']))->getJson('/api/v1/car-reports/sales/export?format=pdf')->assertForbidden();
        $this->actingAs($this->reporter())->getJson('/api/v1/car-reports/cars/export?format=csv')->assertJsonValidationErrors('format');
        $this->actingAs($this->reporter())->getJson('/api/v1/car-reports/secrets/export?format=xlsx')->assertNotFound();
    }

    public function test_export_is_audited(): void
    {
        $user = $this->reporter();
        $this->actingAs($user)->get('/api/v1/car-reports/sales/export?format=xlsx&from=2026-09-01')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'car.report_exported', 'entity_id' => 'sales', 'user_id' => $user->id]);
    }

    public function test_exports_above_the_row_limit_are_refused(): void
    {
        ReportExporter::$limits = ['xlsx' => 1, 'pdf' => 1];

        $this->actingAs($this->reporter())->getJson('/api/v1/car-reports/cars/export?format=pdf')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['format' => 'This report has 2 rows; the export limit is 1. Narrow the filters and try again.']);
        $this->actingAs($this->reporter())->getJson('/api/v1/car-reports/cars/export?format=pdf&state=sold')->assertOk();
    }
}
