<?php

namespace Tests\Feature\Administration;

use App\Modules\Administration\Models\Setting;
use App\Modules\Administration\Services\BusinessProfiles;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Branch\Models\Branch;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Models\MenuItem;
use App\Modules\Restaurant\Services\PaymentReceipt;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;

class BusinessProfileTest extends AdministrationTestCase
{
    private const CAR = [
        'name' => 'MM Motors',
        'address' => 'House 12, Road 5, Gulshan, Dhaka',
        'phone' => '+880 1711-000001, 02-9876543',
        'email' => 'cars@mm.example',
    ];

    private const RESTAURANT = [
        'name' => 'MM Kitchen & Convention Hall',
        'address' => 'Plot 7, Dhanmondi, Dhaka',
        'phone' => '+880 1811-000002',
        'email' => null,
    ];

    private function save(string $module, array $data, $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->superAdmin())->putJson("/api/v1/business-profiles/{$module}", $data);
    }

    /** @return list<list<mixed>> first sheet rows (the reader skips blank rows) */
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

    public function test_profiles_are_empty_until_configured(): void
    {
        $this->actingAs($this->superAdmin())->getJson('/api/v1/business-profiles')->assertOk()
            ->assertJsonPath('data.0.module', 'car')
            ->assertJsonPath('data.0.label', 'Car business')
            ->assertJsonPath('data.0.name', null)
            ->assertJsonPath('data.0.configured', false)
            ->assertJsonPath('data.1.module', 'restaurant');

        // Documents fall back to the application name.
        $this->assertSame(['name' => config('app.name'), 'lines' => []], app(BusinessProfiles::class)->letterhead('car'));
        Setting::create(['key' => 'app.name', 'value' => 'MM Group']);
        $this->assertSame('MM Group', app(BusinessProfiles::class)->letterhead('restaurant')['name']);
    }

    public function test_save_profiles_with_normalization_and_audit(): void
    {
        $this->save('car', ['name' => '  MM   Motors ', 'address' => self::CAR['address'], 'phone' => self::CAR['phone'], 'email' => self::CAR['email']])
            ->assertOk()
            ->assertJsonPath('data.name', 'MM Motors')
            ->assertJsonPath('data.phone', '+880 1711-000001, 02-9876543')
            ->assertJsonPath('data.configured', true);
        $this->save('restaurant', self::RESTAURANT)->assertOk()->assertJsonPath('data.email', null);

        $this->actingAs($this->superAdmin())->getJson('/api/v1/business-profiles/restaurant')
            ->assertJsonPath('data.name', 'MM Kitchen & Convention Hall')->assertJsonPath('data.address', 'Plot 7, Dhanmondi, Dhaka');

        // Clearing optional fields.
        $this->save('car', ['name' => 'MM Motors', 'address' => '', 'phone' => null])->assertOk()
            ->assertJsonPath('data.address', null)->assertJsonPath('data.email', null);

        $logs = AuditLog::where('action', 'setting.updated')->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(3, $logs);
        $this->assertSame('business_profile.car', $logs[0]->new_values['key']);
        $this->assertSame('MM Motors', $logs[0]->new_values['value']['name']);
        $this->assertSame(self::CAR['address'], $logs[2]->old_values['value']['address']);
    }

    public function test_validation(): void
    {
        $this->save('car', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->save('car', ['name' => '   '])->assertJsonValidationErrors('name');
        $this->save('car', ['name' => str_repeat('x', 151)])->assertJsonValidationErrors('name');
        $this->save('car', ['name' => 'X', 'email' => 'not-an-email'])->assertJsonValidationErrors('email');
        $this->save('car', ['name' => 'X', 'phone' => 'call <me>'])->assertJsonValidationErrors('phone');
        $this->save('car', ['name' => 'X', 'address' => str_repeat('x', 501)])->assertJsonValidationErrors('address');
        $this->save('real-estate', ['name' => 'X'])->assertNotFound();

        $this->assertSame(0, Setting::where('key', 'like', 'business_profile.%')->count());
    }

    public function test_permissions_and_generic_settings_cannot_bypass_validation(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/business-profiles')->assertUnauthorized();

        $viewer = $this->userWith(['setting.view']);
        $this->actingAs($viewer)->getJson('/api/v1/business-profiles/car')->assertOk();
        $this->save('car', self::CAR, $viewer)->assertForbidden();
        $this->actingAs($this->userWith(['car.report.view']))->getJson('/api/v1/business-profiles')->assertForbidden();

        $editor = $this->userWith(['setting.view', 'setting.update']);
        $this->actingAs($editor)->putJson('/api/v1/settings/business_profile.car', ['value' => ['name' => '<script>']])
            ->assertUnprocessable()->assertJsonValidationErrors(['key' => 'Business profiles are edited in the business profile settings.']);
        $this->actingAs($editor)->putJson('/api/v1/settings/app.name', ['value' => 'MM Group'])->assertOk();
        $this->save('car', self::CAR, $editor)->assertOk();
    }

    public function test_each_module_prints_its_own_letterhead(): void
    {
        $this->save('car', self::CAR)->assertOk();
        $this->save('restaurant', self::RESTAURANT)->assertOk();

        // Car report export (Excel): car letterhead above the title.
        $car = $this->sheetRows($this->actingAs($this->userWith(['car.report.view', 'car.view', 'branch.access_all']))
            ->get('/api/v1/car-reports/cars/export?format=xlsx')->assertOk());
        $this->assertSame(['MM Motors'], [$car[0][0]]);
        $this->assertSame(self::CAR['address'], $car[1][0]);
        $this->assertSame('Phone: +880 1711-000001, 02-9876543 · Email: cars@mm.example', $car[2][0]);

        // Restaurant report export (Excel): restaurant letterhead.
        $restaurant = $this->sheetRows($this->actingAs($this->userWith(['restaurant.report.view', 'restaurant.sale.view', 'branch.access_all']))
            ->get('/api/v1/restaurant/reports/sales/export?format=xlsx')->assertOk());
        $this->assertSame('MM Kitchen & Convention Hall', $restaurant[0][0]);
        $this->assertSame('Phone: +880 1811-000002', $restaurant[2][0]);
        $this->assertNotContains('MM Motors', array_column($restaurant, 0));

        // PDFs use the same letterhead.
        $this->actingAs($this->userWith(['car.report.view', 'car.view']))->get('/api/v1/car-reports/cars/export?format=pdf')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Restaurant payment receipt.
        $branch = Branch::factory()->create();
        $cashier = $this->userWith(['restaurant.sale.create', 'restaurant.sale.view'], [$branch]);
        $saleId = $this->actingAs($cashier)->postJson('/api/v1/restaurant/sales', [
            'branch_id' => $branch->id, 'items' => [['menu_item_id' => MenuItem::factory()->create(['price_minor' => 1000])->id, 'quantity' => 1]],
            'payment' => ['amount' => '10', 'method' => 'cash'],
        ])->assertCreated()->json('data.id');
        $sale = FoodSale::find($saleId);
        $receipt = app(PaymentReceipt::class)->saleReceiptData($sale, $sale->payments()->sole());
        $this->assertSame('MM Kitchen & Convention Hall', $receipt['business']);
        $this->assertSame([self::RESTAURANT['address'], 'Phone: +880 1811-000002'], $receipt['business_lines']);
    }
}
