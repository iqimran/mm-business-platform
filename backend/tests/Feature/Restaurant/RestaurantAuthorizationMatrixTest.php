<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Models\EventMenuItem;
use App\Modules\Restaurant\Models\ExpenseCategory;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Models\Hall;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Models\MenuItem;
use App\Modules\Restaurant\Models\RestaurantCustomer;
use App\Modules\Restaurant\Models\RestaurantExpense;
use App\Modules\Restaurant\Models\RestaurantSupplier;
use Database\Seeders\PermissionSeeder;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * Walks EVERY /api/v1/restaurant route (so new routes are covered automatically):
 * - unauthenticated → 401
 * - signed in with every non-restaurant permission → 403
 * - every restaurant permission but another branch → no access to branch-scoped records
 */
class RestaurantAuthorizationMatrixTest extends RestaurantTestCase
{
    /** @var array<string, string> route parameter => record id */
    private array $ids = [];

    private string $salePaymentId;

    private string $bookingPaymentId;

    private string $expensePaymentId;

    protected function setUp(): void
    {
        parent::setUp();

        $all = array_values(array_filter(array_keys(PermissionSeeder::PERMISSIONS), fn ($p) => str_starts_with($p, 'restaurant.')));
        $owner = $this->userWith($all, [$this->branchA]);
        $customer = RestaurantCustomer::factory()->create();
        $item = MenuItem::factory()->create(['price_minor' => 10000]);
        $hall = Hall::factory()->create(['branch_id' => $this->branchA->id]);
        $category = ExpenseCategory::factory()->create();

        $sale = $this->actingAs($owner)->postJson('/api/v1/restaurant/sales', [
            'branch_id' => $this->branchA->id, 'customer_id' => $customer->id,
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1]], 'payment' => ['amount' => '50', 'method' => 'cash'],
        ])->assertCreated()->json('data.id');
        $booking = $this->actingAs($owner)->postJson('/api/v1/restaurant/hall-bookings', [
            'hall_id' => $hall->id, 'customer_id' => $customer->id, 'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '12:00', 'agreed_amount' => '1000', 'payment' => ['amount' => '100', 'method' => 'cash'],
        ])->assertCreated()->json('data.id');
        // A supplier bill, partly paid (supplier dues).
        $supplier = RestaurantSupplier::factory()->create();
        $expense = $this->actingAs($owner)->postJson('/api/v1/restaurant/expenses', [
            'branch_id' => $this->branchA->id, 'category_id' => $category->id, 'expense_date' => now()->toDateString(), 'amount' => '10',
            'supplier_id' => $supplier->id, 'paid_amount' => '4', 'payment_method' => 'cash',
        ])->assertCreated()->json('data.id');

        $this->ids = [
            'customer' => $customer->id,
            'supplier' => $supplier->id,
            'menuCategory' => $item->category_id,
            'menuItem' => $item->id,
            'eventMenuItem' => EventMenuItem::factory()->create()->id,
            'sale' => $sale,
            'hall' => $hall->id,
            'booking' => $booking,
            'expenseCategory' => $category->id,
            'expense' => $expense,
            'report' => 'sales',
        ];
        $this->salePaymentId = FoodSale::find($sale)->payments()->sole()->id;
        $this->bookingPaymentId = HallBooking::find($booking)->payments()->sole()->id;
        $this->expensePaymentId = RestaurantExpense::find($expense)->payments()->sole()->id;
        $this->assertSame(1, RestaurantExpense::count());
        $this->app['auth']->forgetGuards();
    }

    /** @return list<array{0: string, 1: string}> [method, uri] for every restaurant route */
    private function routes(): array
    {
        $routes = [];
        foreach (Router::getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api/v1/restaurant')) {
                continue;
            }
            $uri = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($route) {
                if ($m[1] === 'payment') {
                    return match (true) {
                        str_contains($route->uri(), 'hall-bookings') => $this->bookingPaymentId,
                        str_contains($route->uri(), 'expenses') => $this->expensePaymentId,
                        default => $this->salePaymentId,
                    };
                }

                return $this->ids[$m[1]] ?? $this->fail("No fixture for route parameter {$m[1]} in {$route->uri()}");
            }, $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = [$method, '/'.$uri.(str_contains($uri, 'hall-availability') ? "?hall_id={$this->ids['hall']}&date=".now()->toDateString() : '')];
            }
        }

        return $routes;
    }

    public function test_every_route_requires_authentication(): void
    {
        $routes = $this->routes();
        $this->assertGreaterThanOrEqual(50, count($routes));

        foreach ($routes as [$method, $uri]) {
            $this->json($method, $uri)->assertStatus(401);
        }
    }

    public function test_every_route_requires_a_restaurant_permission(): void
    {
        // Everything except restaurant permissions (incl. global branch access and admin rights).
        $others = array_values(array_filter(array_keys(PermissionSeeder::PERMISSIONS), fn ($p) => ! str_starts_with($p, 'restaurant.')));
        $outsider = $this->userWith($others, [$this->branchA, $this->branchB]);

        foreach ($this->routes() as [$method, $uri]) {
            $status = $this->actingAs($outsider)->json($method, $uri)->status();
            $this->assertSame(403, $status, "{$method} {$uri} returned {$status} without restaurant permissions.");
        }
    }

    public function test_branch_scoped_records_are_isolated_on_every_route(): void
    {
        $all = array_values(array_filter(array_keys(PermissionSeeder::PERMISSIONS), fn ($p) => str_starts_with($p, 'restaurant.')));
        $otherBranch = $this->userWith($all, [$this->branchB]);
        $scoped = [$this->ids['sale'], $this->ids['booking'], $this->ids['hall'], $this->ids['expense']];

        $checked = 0;
        foreach ($this->routes() as [$method, $uri]) {
            if (! collect($scoped)->contains(fn ($id) => str_contains($uri, $id))) {
                continue;
            }
            $status = $this->actingAs($otherBranch)->json($method, $uri)->status();
            $this->assertContains($status, [403, 404], "{$method} {$uri} returned {$status} for a user of another branch.");
            $checked++;
        }
        $this->assertGreaterThanOrEqual(20, $checked);

        // Nothing changed.
        $this->assertFalse(FoodSale::find($this->ids['sale'])->isReversed());
        $this->assertSame('confirmed', HallBooking::find($this->ids['booking'])->status->value);
        $this->assertNotNull(Hall::find($this->ids['hall']));
        $this->assertFalse(RestaurantExpense::find($this->ids['expense'])->isReversed());
    }
}
