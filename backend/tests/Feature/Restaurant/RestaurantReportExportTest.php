<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\ExpenseCategory;
use App\Modules\Restaurant\Models\Hall;
use App\Modules\Restaurant\Models\MenuItem;
use App\Modules\Restaurant\Models\RestaurantCustomer;
use App\Modules\Restaurant\Reports\ReportExporter;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;

class RestaurantReportExportTest extends RestaurantTestCase
{
    private const EXPORT = '/api/v1/restaurant/reports';

    private const VIEW = ['restaurant.report.view', 'restaurant.sale.view', 'restaurant.booking.view', 'restaurant.expense.view'];

    private User $reporter;

    private array $originalLimits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalLimits = ReportExporter::$limits;

        $operator = $this->userWith(['restaurant.sale.create', 'restaurant.booking.create', 'restaurant.expense.create'], [$this->branchA, $this->branchB]);
        $this->reporter = $this->userWith(self::VIEW, [$this->branchA]);
        $customer = RestaurantCustomer::factory()->create(['name' => 'Karim']);
        $tea = MenuItem::factory()->create(['price_minor' => 10000]);

        foreach ([[$this->branchA, 2, '200'], [$this->branchA, 3, '0'], [$this->branchB, 1, '100']] as [$branch, $qty, $paid]) {
            $this->actingAs($operator)->postJson('/api/v1/restaurant/sales', [
                'branch_id' => $branch->id, 'customer_id' => $customer->id, 'items' => [['menu_item_id' => $tea->id, 'quantity' => $qty]],
            ] + ($paid !== '0' ? ['payment' => ['amount' => $paid, 'method' => 'cash']] : []))->assertCreated();
        }

        $hall = Hall::factory()->create(['branch_id' => $this->branchA->id, 'name' => 'Grand']);
        $this->actingAs($operator)->postJson('/api/v1/restaurant/hall-bookings', [
            'hall_id' => $hall->id, 'customer_id' => $customer->id, 'booking_date' => now()->toDateString(),
            'start_time' => '18:00', 'end_time' => '22:00', 'agreed_amount' => '1000', 'payment' => ['amount' => '400', 'method' => 'cash'],
        ])->assertCreated();

        $food = ExpenseCategory::factory()->create(['name' => 'Food Purchase']);
        $this->actingAs($operator)->postJson('/api/v1/restaurant/expenses', [
            'branch_id' => $this->branchA->id, 'category_id' => $food->id, 'expense_date' => now()->toDateString(), 'amount' => '1234.50',
        ])->assertCreated();
    }

    protected function tearDown(): void
    {
        ReportExporter::$limits = $this->originalLimits;
        parent::tearDown();
    }

    /**
     * Spreadsheet rows; numbers are compared with assertEquals because the reader returns whole numbers as int.
     *
     * @return list<list<mixed>>
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
                // The reader pads rows to the widest one; trailing empty cells are not data.
                $values = $row->toArray();
                while ($values !== [] && in_array(end($values), [null, ''], true)) {
                    array_pop($values);
                }
                $rows[] = $values;
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
        $i = collect($rows)->search(fn ($r) => ($r[0] ?? null) === $firstHeader);

        return ['header' => $rows[$i], 'body' => array_slice($rows, $i + 1), 'meta' => array_slice($rows, 0, $i)];
    }

    public function test_sales_excel_export_has_rows_numeric_totals_and_header(): void
    {
        $response = $this->actingAs($this->reporter)->get(self::EXPORT.'/sales/export?format=xlsx&sort=total&direction=asc')
            ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('restaurant-sales-', $response->headers->get('Content-Disposition'));

        ['header' => $header, 'body' => $body, 'meta' => $meta] = $this->table($this->sheetRows($response), 'Sale no.');
        $this->assertSame(['Sale no.', 'Sale time', 'Branch', 'Customer', 'Items', 'Total', 'Received', 'Due', 'Payment status'], $header);
        $this->assertCount(3, $body); // two branch-A sales + total row (branch B is not accessible)
        $this->assertEquals([200.0, 200.0, 0.0, 'paid'], array_slice($body[0], 5, 4));
        $this->assertEquals([300.0, 0.0, 300.0, 'unpaid'], array_slice($body[1], 5, 4));
        $this->assertSame('Total', $body[2][0]);
        $this->assertEquals([500.0, 200.0, 300.0], array_slice($body[2], 5, 3));

        $this->assertSame(config('app.name'), $meta[0][0]); // letterhead (no restaurant profile configured)
        $this->assertSame('Food sales', $meta[1][0]); // the reader skips the blank row
        $metaText = json_encode($meta);
        $this->assertStringContainsString('All accessible branches', $metaText);
        $this->assertStringContainsString('no profit is calculated', $metaText);
    }

    public function test_daily_and_grouped_exports_follow_the_selected_view(): void
    {
        $daily = $this->table($this->sheetRows($this->actingAs($this->reporter)->get(self::EXPORT.'/sales/export?format=xlsx&group_by=day')), 'Date');
        $this->assertSame(['Date', 'Sales', 'Total', 'Received', 'Due'], $daily['header']);
        $this->assertEquals([now()->toDateString(), 2, 500.0, 200.0, 300.0], $daily['body'][0]);

        $categories = $this->table($this->sheetRows($this->actingAs($this->reporter)->get(self::EXPORT.'/expenses/export?format=xlsx&group_by=category')), 'Category');
        $this->assertEquals(['Food Purchase', 1, 1234.5], $categories['body'][0]);

        $bookings = $this->table($this->sheetRows($this->actingAs($this->reporter)->get(self::EXPORT.'/bookings/export?format=xlsx')), 'Booking no.');
        $this->assertEquals(['Grand', 'Karim', 'confirmed', 1000.0, 400.0, 600.0, 'partial'], array_slice($bookings['body'][0], 4, 7));
        $this->assertSame('Total', $bookings['body'][1][0]);
    }

    public function test_filters_and_branch_are_applied_and_shown(): void
    {
        $global = $this->userWith([...self::VIEW, 'branch.access_all']);
        $all = $this->table($this->sheetRows($this->actingAs($global)->get(self::EXPORT.'/sales/export?format=xlsx')), 'Sale no.');
        $this->assertCount(4, $all['body']);

        $b = $this->table($this->sheetRows($this->actingAs($global)->get(self::EXPORT."/sales/export?format=xlsx&branch_id={$this->branchB->id}&payment_status=paid")), 'Sale no.');
        $this->assertCount(2, $b['body']);
        $metaText = json_encode($b['meta']);
        $this->assertStringContainsString($this->branchB->code, $metaText);
        $this->assertStringContainsString('Payment status', $metaText);

        $date = now()->subDays(3)->toDateString();
        $none = $this->table($this->sheetRows($this->actingAs($this->reporter)->get(self::EXPORT."/sales/export?format=xlsx&date={$date}")), 'Sale no.');
        $this->assertSame([], $none['body']);
    }

    public function test_summary_export(): void
    {
        $date = now()->toDateString();
        ['header' => $header, 'body' => $body] = $this->table(
            $this->sheetRows($this->actingAs($this->reporter)->get(self::EXPORT."/summary/export?format=xlsx&date={$date}")), 'Area');

        $this->assertSame(['Area', 'Figure', 'Count', 'Amount'], $header);
        $figures = collect($body)->mapWithKeys(fn ($r) => [$r[0].' / '.$r[1] => $r[3] ?? $r[2]])->all();
        $this->assertEquals(500.0, $figures['Food sales / Revenue']);
        $this->assertEquals(300.0, $figures['Food sales / Outstanding due']);
        $this->assertEquals(1000.0, $figures['Hall bookings / Revenue']);
        $this->assertEquals(600.0, $figures['Hall bookings / Outstanding due']);
        $this->assertEquals(1234.5, $figures['Restaurant expenses / Total expenses']);
        $this->assertArrayNotHasKey('Total', collect($body)->keyBy(0)->all()); // no grand total / profit row

        // Areas the user may not view are left out.
        $salesOnly = $this->userWith(['restaurant.report.view', 'restaurant.sale.view'], [$this->branchA]);
        $rows = $this->table($this->sheetRows($this->actingAs($salesOnly)->get(self::EXPORT."/summary/export?format=xlsx&date={$date}")), 'Area')['body'];
        $this->assertSame(['Food sales'], collect($rows)->pluck(0)->unique()->values()->all());
    }

    public function test_pdf_exports(): void
    {
        foreach (['sales', 'bookings', 'expenses', 'summary'] as $report) {
            $response = $this->actingAs($this->reporter)->get(self::EXPORT."/{$report}/export?format=pdf")
                ->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
            $this->assertStringContainsString("restaurant-{$report}-", $response->headers->get('Content-Disposition'));
        }

        $this->assertSame(4, AuditLog::where('action', 'restaurant.report_exported')->count());
        $log = AuditLog::where('action', 'restaurant.report_exported')->where('entity_id', 'sales')->sole();
        $this->assertSame('pdf', $log->new_values['format']);
        $this->assertSame(2, $log->new_values['rows']);
    }

    public function test_export_authorization_and_validation(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson(self::EXPORT.'/sales/export?format=xlsx')->assertUnauthorized();

        $noReports = $this->userWith(['restaurant.sale.view', 'restaurant.expense.view'], [$this->branchA]);
        $this->actingAs($noReports)->getJson(self::EXPORT.'/sales/export?format=xlsx')->assertForbidden();
        $this->actingAs($noReports)->getJson(self::EXPORT.'/summary/export?format=pdf')->assertForbidden();

        $salesOnly = $this->userWith(['restaurant.report.view', 'restaurant.sale.view'], [$this->branchA]);
        $this->actingAs($salesOnly)->getJson(self::EXPORT.'/expenses/export?format=xlsx')->assertForbidden();
        $this->actingAs($salesOnly)->getJson(self::EXPORT.'/bookings/export?format=pdf')->assertForbidden();

        $this->actingAs($this->reporter)->getJson(self::EXPORT.'/sales/export')->assertJsonValidationErrors('format');
        $this->actingAs($this->reporter)->getJson(self::EXPORT.'/sales/export?format=csv')->assertJsonValidationErrors('format');
        $this->actingAs($this->reporter)->getJson(self::EXPORT.'/sales/export?format=xlsx&sort=profit')->assertJsonValidationErrors('sort');
        $this->actingAs($this->reporter)->getJson(self::EXPORT.'/profit/export?format=xlsx')->assertNotFound();
        $this->assertSame(0, AuditLog::where('action', 'restaurant.report_exported')->count());
    }

    public function test_user_entered_text_is_never_exported_as_a_formula(): void
    {
        $evil = '=HYPERLINK("http://evil.example","Click")';
        RestaurantCustomer::query()->update(['name' => $evil]);

        $response = $this->actingAs($this->reporter)->get(self::EXPORT.'/sales/export?format=xlsx')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent() ?: file_get_contents($response->baseResponse->getFile()->getPathname()));
        $zip = new \ZipArchive;
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringContainsString(htmlspecialchars($evil, ENT_QUOTES | ENT_XML1), $sheet);
        // The value is still exported as text.
        $this->assertContains($evil, array_column($this->table($this->sheetRows($this->actingAs($this->reporter)->get(self::EXPORT.'/sales/export?format=xlsx')), 'Sale no.')['body'], 3));
    }

    public function test_export_row_limit(): void
    {
        ReportExporter::$limits = ['xlsx' => 1, 'pdf' => 1];

        $this->actingAs($this->reporter)->getJson(self::EXPORT.'/sales/export?format=xlsx')
            ->assertUnprocessable()->assertJsonValidationErrors(['format' => 'This report has 2 rows; the export limit is 1. Narrow the filters and try again.']);
        $this->actingAs($this->reporter)->get(self::EXPORT.'/expenses/export?format=xlsx')->assertOk();
    }
}
