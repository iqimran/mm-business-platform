<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\EventMenuItem;
use App\Modules\Restaurant\Models\Hall;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Models\HallBookingFoodPackage;
use App\Modules\Restaurant\Models\HallBookingFoodPackageItem;
use App\Modules\Restaurant\Models\MenuItem;
use App\Modules\Restaurant\Models\RestaurantCustomer;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * Event food package of a hall booking:
 *   package total = guest count × price per head; booking total = hall charge + package total;
 *   due = booking total − payments (existing booking payments).
 */
class HallBookingFoodPackageTest extends RestaurantTestCase
{
    private const BOOKINGS = '/api/v1/restaurant/hall-bookings';

    private const ALL = [
        'restaurant.booking.view', 'restaurant.booking.create', 'restaurant.booking.update', 'restaurant.booking.cancel',
        'restaurant.booking_payment.create', 'restaurant.booking_payment.reverse',
    ];

    private User $manager;

    private Hall $hall;

    private RestaurantCustomer $customer;

    /** @var array<string, EventMenuItem> */
    private array $menu = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->userWith(self::ALL, [$this->branchA]);
        $this->hall = Hall::factory()->create(['branch_id' => $this->branchA->id, 'name' => 'Grand Hall', 'capacity' => 250]);
        $this->customer = RestaurantCustomer::factory()->create();
        // Package items come from the event menu (no prices), not from the food menu.
        foreach (['Polao', 'Roast', 'Beef', 'Borhani', 'Jorda', 'Tikka', 'Vegetable'] as $name) {
            $this->menu[$name] = EventMenuItem::factory()->create(['name' => $name]);
        }
    }

    private function ids(string ...$names): array
    {
        return array_map(fn ($n) => $this->menu[$n]->id, $names);
    }

    private function package(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Wedding Dinner Package',
            'guest_count' => 300,
            'price_per_head' => '800',
            'event_menu_item_ids' => $this->ids('Polao', 'Roast', 'Beef', 'Borhani', 'Jorda', 'Tikka', 'Vegetable'),
        ];
    }

    private function book(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->manager)->postJson(self::BOOKINGS, $overrides + [
            'hall_id' => $this->hall->id,
            'customer_id' => $this->customer->id,
            'booking_date' => now()->addDays(10)->toDateString(),
            'start_time' => '18:00',
            'end_time' => '23:00',
            'hall_charge' => '100000',
        ]);
    }

    private function update(string $id, array $data, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->manager)->patchJson(self::BOOKINGS."/{$id}", $data);
    }

    private function pay(string $id, string $amount, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->manager)->postJson(self::BOOKINGS."/{$id}/payments", [
            'payment_date' => now()->toDateString(), 'amount' => $amount, 'method' => 'cash',
        ]);
    }

    // ---- Creation and calculation --------------------------------------------------------------

    public function test_booking_without_package(): void
    {
        $this->book()->assertCreated()
            ->assertJsonPath('data.hall_charge', '100000.00')
            ->assertJsonPath('data.food_package', null)
            ->assertJsonPath('data.booking_total', '100000.00')
            ->assertJsonPath('data.due', '100000.00');
        $this->assertSame(0, HallBookingFoodPackage::count());
    }

    public function test_booking_with_package_300_guests_at_800(): void
    {
        $response = $this->book(['food_package' => $this->package(['notes' => ' Serve at 8 pm '])])->assertCreated()
            ->assertJsonPath('data.hall_charge', '100000.00')
            ->assertJsonPath('data.food_package.name', 'Wedding Dinner Package')
            ->assertJsonPath('data.food_package.guest_count', 300)
            ->assertJsonPath('data.food_package.price_per_head', '800.00')
            ->assertJsonPath('data.food_package.total', '240000.00')
            ->assertJsonPath('data.food_package.notes', 'Serve at 8 pm')
            ->assertJsonPath('data.booking_total', '340000.00')
            ->assertJsonPath('data.paid', '0.00')
            ->assertJsonPath('data.due', '340000.00')
            ->assertJsonPath('data.payment_status', 'unpaid');

        $this->assertSame(['Polao', 'Roast', 'Beef', 'Borhani', 'Jorda', 'Tikka', 'Vegetable'], array_column($response->json('data.food_package.items'), 'item_name'));
        $this->assertSame(24000000, HallBookingFoodPackage::sole()->total_minor);
        $this->assertSame(34000000, HallBooking::sole()->agreed_amount_minor);
    }

    public function test_package_with_one_item_and_other_amounts(): void
    {
        $this->book(['food_package' => $this->package(['guest_count' => 150, 'price_per_head' => '1250.50', 'event_menu_item_ids' => $this->ids('Polao')])])
            ->assertCreated()
            ->assertJsonPath('data.food_package.total', '187575.00')
            ->assertJsonCount(1, 'data.food_package.items')
            ->assertJsonPath('data.booking_total', '287575.00');

        $this->book(['start_time' => '08:00', 'end_time' => '10:00', 'hall_charge' => '0.50',
            'food_package' => $this->package(['guest_count' => 1, 'price_per_head' => '0.01', 'event_menu_item_ids' => $this->ids('Tikka', 'Jorda')])])
            ->assertCreated()->assertJsonPath('data.food_package.total', '0.01')->assertJsonPath('data.booking_total', '0.51');
    }

    public function test_hall_charge_may_be_zero_with_a_package(): void
    {
        $this->book(['hall_charge' => '0', 'food_package' => $this->package()])->assertCreated()
            ->assertJsonPath('data.hall_charge', '0.00')
            ->assertJsonPath('data.booking_total', '240000.00');

        // Without a package the booking must have a hall charge.
        $this->book(['hall_charge' => '0', 'start_time' => '08:00', 'end_time' => '10:00'])
            ->assertUnprocessable()->assertJsonValidationErrors(['hall_charge' => 'Enter a hall charge or add a food package.']);
    }

    public function test_client_totals_are_ignored(): void
    {
        $this->book(['booking_total' => '1', 'agreed_amount_minor' => 1, 'food_package' => $this->package(['total' => '5', 'total_minor' => 5])])
            ->assertCreated()->assertJsonPath('data.food_package.total', '240000.00')->assertJsonPath('data.booking_total', '340000.00');
    }

    // ---- Validation ----------------------------------------------------------------------------

    public function test_package_validation(): void
    {
        foreach ([0, -5, 1.5, 'many', 100001] as $guests) {
            $this->book(['food_package' => $this->package(['guest_count' => $guests])])->assertJsonValidationErrors('food_package.guest_count');
        }
        foreach (['0', '0.00', '-800', 800.5, '1.234', '1,000'] as $price) {
            $this->book(['food_package' => $this->package(['price_per_head' => $price])])->assertJsonValidationErrors('food_package.price_per_head');
        }
        $this->book(['food_package' => $this->package(['event_menu_item_ids' => []])])
            ->assertJsonValidationErrors(['food_package.event_menu_item_ids' => 'Select at least one event menu item.']);
        $this->book(['food_package' => $this->package(['event_menu_item_ids' => ['01JAAAAAAAAAAAAAAAAAAAAAAA']])])
            ->assertJsonValidationErrors(['food_package.event_menu_item_ids.0' => 'This event menu item is not available.']);
        $this->book(['food_package' => $this->package(['event_menu_item_ids' => $this->ids('Polao', 'Polao')])])
            ->assertJsonValidationErrors('food_package.event_menu_item_ids.1');
        $this->book(['food_package' => $this->package(['name' => '  '])])->assertJsonValidationErrors('food_package.name');
        $this->book(['food_package' => ['guest_count' => 10]])->assertJsonValidationErrors('food_package');
        foreach ([-1, '1.234', 'abc'] as $charge) {
            $this->book(['hall_charge' => $charge])->assertJsonValidationErrors('hall_charge');
        }

        // Inactive event menu items cannot be added; ordinary food menu items are not package items.
        $this->menu['Beef']->update(['is_active' => false]);
        $this->book(['food_package' => $this->package(['event_menu_item_ids' => $this->ids('Polao', 'Beef')])])
            ->assertJsonValidationErrors(['food_package.event_menu_item_ids.1' => 'This event menu item is not available.']);
        $foodMenuItem = MenuItem::factory()->create();
        $this->book(['food_package' => $this->package(['event_menu_item_ids' => [$foodMenuItem->id]])])
            ->assertJsonValidationErrors(['food_package.event_menu_item_ids.0' => 'This event menu item is not available.']);

        // Too large.
        $this->book(['food_package' => $this->package(['guest_count' => 100000, 'price_per_head' => '999999999999.99'])])
            ->assertJsonValidationErrors('food_package.guest_count');

        $this->assertSame(0, HallBooking::count());
        $this->assertSame(0, HallBookingFoodPackage::count());
    }

    // ---- Payments ------------------------------------------------------------------------------

    public function test_unpaid_partial_and_full_payment_against_the_booking_total(): void
    {
        $id = $this->book(['food_package' => $this->package()])->json('data.id');

        $this->pay($id, '100000')->assertCreated()
            ->assertJsonPath('data.paid', '100000.00')->assertJsonPath('data.due', '240000.00')->assertJsonPath('data.payment_status', 'partial');
        $this->pay($id, '240000.01')->assertJsonValidationErrors(['amount' => 'The amount exceeds the remaining due of 240000.00.']);
        $this->pay($id, '240000')->assertCreated()->assertJsonPath('data.due', '0.00')->assertJsonPath('data.payment_status', 'paid');

        // Advance payment with the booking is limited by the booking total, not just the hall charge.
        $this->book(['start_time' => '08:00', 'end_time' => '10:00', 'hall_charge' => '1000', 'food_package' => $this->package(['guest_count' => 10, 'price_per_head' => '100']),
            'payment' => ['amount' => '2000', 'method' => 'cash']])
            ->assertCreated()->assertJsonPath('data.paid', '2000.00')->assertJsonPath('data.payment_status', 'paid');
        $this->book(['start_time' => '11:00', 'end_time' => '12:00', 'hall_charge' => '1000', 'food_package' => $this->package(['guest_count' => 10, 'price_per_head' => '100']),
            'payment' => ['amount' => '2000.01', 'method' => 'cash']])
            ->assertJsonValidationErrors(['payment.amount' => 'The payment exceeds the booking total of 2000.00.']);
    }

    // ---- Lifecycle -----------------------------------------------------------------------------

    public function test_package_can_be_added_changed_and_removed_while_confirmed_with_audit(): void
    {
        $id = $this->book()->json('data.id');

        $this->update($id, ['food_package' => $this->package(['event_menu_item_ids' => $this->ids('Polao', 'Roast')])])->assertOk()
            ->assertJsonPath('data.booking_total', '340000.00')->assertJsonCount(2, 'data.food_package.items');

        $this->update($id, ['food_package' => $this->package(['guest_count' => 350, 'event_menu_item_ids' => $this->ids('Polao', 'Beef', 'Jorda')])])->assertOk()
            ->assertJsonPath('data.food_package.total', '280000.00')
            ->assertJsonPath('data.booking_total', '380000.00')
            ->assertJsonPath('data.food_package.items.2.item_name', 'Jorda');
        $this->assertSame(3, HallBookingFoodPackageItem::count());

        // Changing other booking fields leaves the package alone.
        $this->update($id, ['notes' => 'VIP'])->assertOk()->assertJsonPath('data.food_package.guest_count', 350);

        $this->update($id, ['food_package' => null])->assertOk()
            ->assertJsonPath('data.food_package', null)->assertJsonPath('data.booking_total', '100000.00');
        $this->assertSame(0, HallBookingFoodPackage::count());
        $this->assertSame(0, HallBookingFoodPackageItem::count());

        $logs = AuditLog::where('action', 'like', 'restaurant.booking.food_package_%')->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame([
            'restaurant.booking.food_package_added', 'restaurant.booking.food_package_updated', 'restaurant.booking.food_package_removed',
        ], $logs->pluck('action')->all());
        $this->assertNull($logs[0]->old_values['food_package']);
        $this->assertSame('100000.00', $logs[0]->old_values['booking_total']);
        $this->assertSame('340000.00', $logs[0]->new_values['booking_total']);
        $this->assertSame(300, $logs[1]->old_values['food_package']['guest_count']);
        $this->assertSame(350, $logs[1]->new_values['food_package']['guest_count']);
        $this->assertSame(['Polao', 'Beef', 'Jorda'], array_column($logs[1]->new_values['food_package']['items'], 'name'));
        $this->assertSame('280000.00', $logs[2]->old_values['food_package']['total']);
        $this->assertNull($logs[2]->new_values['food_package']);
        $this->assertSame($this->branchA->id, $logs[2]->branch_id);

        $totals = AuditLog::where('action', 'restaurant.booking.updated')->get()->pluck('new_values.booking_total')->filter()->values()->all();
        $this->assertSame(['340000.00', '380000.00', '100000.00'], $totals);
    }

    public function test_changes_cannot_drop_the_total_below_what_was_paid(): void
    {
        $id = $this->book(['hall_charge' => '50000', 'food_package' => $this->package()])->json('data.id');
        $this->pay($id, '200000')->assertCreated();

        $this->update($id, ['food_package' => null])->assertUnprocessable()
            ->assertJsonValidationErrors(['hall_charge' => 'The booking total (50000.00) cannot be less than the amount already paid (200000.00).']);
        $this->update($id, ['food_package' => $this->package(['guest_count' => 100])])->assertJsonValidationErrors('hall_charge');
        $this->update($id, ['food_package' => $this->package(['guest_count' => 188])])->assertOk()->assertJsonPath('data.due', '400.00'); // 50000 + 188 × 800 = 200400; paid 200000

        $this->assertSame('188', (string) HallBookingFoodPackage::sole()->guest_count);
    }

    public function test_package_is_locked_after_completion_or_cancellation(): void
    {
        $today = $this->book(['booking_date' => now()->toDateString(), 'food_package' => $this->package()])->json('data.id');
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$today}/complete")->assertOk();
        $this->update($today, ['food_package' => null])->assertStatus(409);
        $this->update($today, ['food_package' => $this->package(['guest_count' => 10])])->assertStatus(409);

        $cancelled = $this->book(['start_time' => '08:00', 'end_time' => '10:00', 'food_package' => $this->package()])->json('data.id');
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$cancelled}/cancel", ['reason' => 'Event called off'])->assertOk();
        $this->update($cancelled, ['food_package' => null])->assertStatus(409);

        // The database refuses package changes of non-confirmed bookings too.
        $package = HallBooking::find($cancelled)->foodPackage;
        $this->assertRejected(fn () => DB::table('restaurant_hall_booking_food_packages')->where('id', $package->id)->update(['guest_count' => 1, 'total_minor' => 80000]), 'cannot be changed');
        $this->assertRejected(fn () => DB::table('restaurant_hall_booking_food_package_items')->where('package_id', $package->id)->delete(), 'cannot be changed');

        $this->assertSame(2, HallBookingFoodPackage::count());
    }

    // ---- Menu changes never alter agreed packages -------------------------------------------------

    public function test_event_menu_changes_do_not_alter_an_existing_package(): void
    {
        $id = $this->book(['food_package' => $this->package(['event_menu_item_ids' => $this->ids('Polao', 'Roast')])])->json('data.id');

        $this->menu['Polao']->update(['name' => 'Kacchi Polao']);
        $this->menu['Roast']->update(['is_active' => false]);

        $this->actingAs($this->manager)->getJson(self::BOOKINGS."/{$id}")
            ->assertJsonPath('data.food_package.items.0.item_name', 'Polao')
            ->assertJsonPath('data.food_package.total', '240000.00')
            ->assertJsonPath('data.booking_total', '340000.00');

        // Editing: items that stay keep their agreed name (even if deactivated); new items copy the current name.
        $this->update($id, ['food_package' => $this->package(['event_menu_item_ids' => $this->ids('Polao', 'Roast', 'Tikka')])])->assertOk()
            ->assertJsonPath('data.food_package.items.0.item_name', 'Polao')
            ->assertJsonPath('data.food_package.items.1.item_name', 'Roast')
            ->assertJsonPath('data.food_package.items.2.item_name', 'Tikka');

        // An event menu item used in a package cannot be deleted.
        $menuManager = $this->userWith(['restaurant.event_menu.delete']);
        $this->actingAs($menuManager)->deleteJson("/api/v1/restaurant/event-menu-items/{$this->menu['Tikka']->id}")->assertStatus(409)
            ->assertJsonPath('message', 'This item is part of a hall booking food package. Mark it inactive instead.');
        $this->actingAs($menuManager)->deleteJson("/api/v1/restaurant/event-menu-items/{$this->menu['Vegetable']->id}")->assertOk();
    }

    // ---- Database integrity and transactions ---------------------------------------------------

    public function test_database_enforces_package_integrity(): void
    {
        $id = $this->book(['food_package' => $this->package()])->json('data.id');
        $package = HallBookingFoodPackage::sole();

        // Package total = guests × price per head.
        $this->assertRejected(fn () => DB::table('restaurant_hall_booking_food_packages')->where('id', $package->id)->update(['total_minor' => 1]), 'total_check');
        // Booking total = hall charge + package (checked at commit; forced here).
        $this->assertRejected(function () use ($id) {
            DB::table('restaurant_hall_bookings')->where('id', $id)->update(['agreed_amount_minor' => 100]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, 'must equal hall charge');
        // A package needs at least one item.
        $this->assertRejected(function () use ($package) {
            DB::table('restaurant_hall_booking_food_package_items')->where('package_id', $package->id)->delete();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, 'at least one menu item');
        // The package belongs to the booking's branch.
        $this->assertRejected(fn () => DB::table('restaurant_hall_booking_food_packages')->where('id', $package->id)->update(['branch_id' => $this->branchB->id]), 'foreign key');
        // Hall charge cannot be negative.
        $this->assertRejected(fn () => DB::table('restaurant_hall_bookings')->where('id', $id)->update(['hall_charge_minor' => -1]), 'hall_charge_check');

        $this->assertSame(7, $package->items()->count());
    }

    public function test_failure_part_way_rolls_back_booking_and_package(): void
    {
        $audit = app(AuditLogger::class);
        $this->mock(AuditLogger::class, function ($mock) use ($audit) {
            $mock->shouldReceive('record')->andReturnUsing(function (string $action, ...$args) use ($audit) {
                if ($action === 'restaurant.booking.food_package_added') {
                    throw new RuntimeException('Simulated failure after the package was written.');
                }

                return $audit->record($action, ...$args);
            });
        });

        $this->book(['food_package' => $this->package()])->assertServerError();

        $this->assertSame(0, HallBooking::count());
        $this->assertSame(0, HallBookingFoodPackage::count());
        $this->assertSame(0, HallBookingFoodPackageItem::count());
        $this->assertSame(0, AuditLog::count());
    }

    // ---- Security ------------------------------------------------------------------------------

    public function test_permissions_and_branch_isolation(): void
    {
        $id = $this->book(['food_package' => $this->package()])->json('data.id');

        $viewer = $this->userWith(['restaurant.booking.view'], [$this->branchA]);
        $this->actingAs($viewer)->getJson(self::BOOKINGS."/{$id}")->assertOk()->assertJsonPath('data.food_package.guest_count', 300);
        $this->update($id, ['food_package' => null], $viewer)->assertForbidden();
        $this->book(['start_time' => '08:00', 'end_time' => '10:00', 'food_package' => $this->package()], $viewer)->assertForbidden();
        // Changing the package needs booking update; payments still need payment permission.
        $updater = $this->userWith(['restaurant.booking.update'], [$this->branchA]);
        $this->update($id, ['food_package' => $this->package(['guest_count' => 301])], $updater)->assertOk();
        $this->pay($id, '10', $updater)->assertForbidden();

        $otherBranch = $this->userWith(self::ALL, [$this->branchB]);
        $this->actingAs($otherBranch)->getJson(self::BOOKINGS."/{$id}")->assertForbidden();
        $this->update($id, ['food_package' => null], $otherBranch)->assertForbidden();
        $this->pay($id, '10', $otherBranch)->assertForbidden();
        $this->actingAs($otherBranch)->getJson(self::BOOKINGS)->assertJsonPath('data.pagination.total', 0);

        // The food menu is shared by all branches; the package always takes the booking's branch.
        $hallB = Hall::factory()->create(['branch_id' => $this->branchB->id]);
        $this->book(['hall_id' => $hallB->id, 'food_package' => $this->package()], $otherBranch)->assertCreated();
        $this->assertSame(
            [$this->branchA->id, $this->branchB->id],
            HallBookingFoodPackage::orderBy('created_at')->orderBy('id')->pluck('branch_id')->all(),
        );
        $this->assertSame(301, HallBooking::find($id)->foodPackage->guest_count);
    }

    // ---- Reporting -----------------------------------------------------------------------------

    public function test_reports_keep_food_sales_hall_charges_and_food_packages_separate(): void
    {
        $this->book(['booking_date' => now()->toDateString(), 'food_package' => $this->package(), 'payment' => ['amount' => '100000', 'method' => 'cash']])->assertCreated();
        $this->book(['booking_date' => now()->toDateString(), 'start_time' => '08:00', 'end_time' => '10:00', 'hall_charge' => '20000'])->assertCreated();

        $seller = $this->userWith(['restaurant.sale.create'], [$this->branchA]);
        $this->actingAs($seller)->postJson('/api/v1/restaurant/sales', [
            'branch_id' => $this->branchA->id, 'items' => [['menu_item_id' => MenuItem::factory()->create(['price_minor' => 15000])->id, 'quantity' => 2]],
            'payment' => ['amount' => '300', 'method' => 'cash'],
        ])->assertCreated();

        $reporter = $this->userWith(['restaurant.report.view', 'restaurant.sale.view', 'restaurant.booking.view'], [$this->branchA]);
        $summary = $this->actingAs($reporter)->getJson('/api/v1/restaurant/reports/summary?date='.now()->toDateString())->assertOk()->json('data');

        $this->assertSame('300.00', $summary['food_sales']['revenue']);
        $this->assertSame('120000.00', $summary['hall_bookings']['hall_charges']);
        $this->assertSame('240000.00', $summary['hall_bookings']['food_packages']);
        $this->assertSame(1, $summary['hall_bookings']['food_package_count']);
        $this->assertSame('360000.00', $summary['hall_bookings']['revenue']);
        $this->assertSame('260000.00', $summary['hall_bookings']['outstanding_due']);
        $this->assertArrayNotHasKey('profit', $summary);

        $report = $this->actingAs($reporter)->getJson('/api/v1/restaurant/reports/bookings?sort=booking_total&direction=desc')->assertOk()->json('data');
        $this->assertSame(['hall_charge' => '100000.00', 'food_package' => '240000.00', 'booking_total' => '340000.00'],
            array_intersect_key($report['items'][0], array_flip(['hall_charge', 'food_package', 'booking_total'])));
        $this->assertNull($report['items'][1]['food_package']);
        $this->assertSame(['120000.00', '240000.00', '360000.00'], [$report['totals']['hall_charges'], $report['totals']['food_packages'], $report['totals']['booking_total']]);

        // Food sales report contains only the food sale.
        $this->actingAs($reporter)->getJson('/api/v1/restaurant/reports/sales')->assertJsonPath('data.totals.total', '300.00')->assertJsonPath('data.totals.count', 1);
    }
}
