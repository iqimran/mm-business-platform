<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\EventMenuItem;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Models\FoodSalePayment;
use App\Modules\Restaurant\Models\Hall;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Models\HallBookingPayment;
use App\Modules\Restaurant\Models\MenuItem;
use App\Modules\Restaurant\Models\RestaurantCustomer;
use App\Modules\Restaurant\Services\PaymentReceipt;
use Illuminate\Support\Facades\View;

class PaymentReceiptTest extends RestaurantTestCase
{
    private User $cashier;

    private FoodSale $sale;

    private HallBooking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = $this->userWith([
            'restaurant.sale.view', 'restaurant.sale.create', 'restaurant.sale_payment.create', 'restaurant.sale_payment.reverse',
            'restaurant.booking.view', 'restaurant.booking.create', 'restaurant.booking_payment.create', 'restaurant.booking_payment.reverse',
        ], [$this->branchA]);
        $customer = RestaurantCustomer::factory()->create(['name' => 'Karim Uddin', 'phone' => '+8801711000001']);
        $biryani = MenuItem::factory()->create(['name' => 'Kacchi Biryani', 'price_minor' => 35000]);

        $saleId = $this->actingAs($this->cashier)->postJson('/api/v1/restaurant/sales', [
            'branch_id' => $this->branchA->id, 'customer_id' => $customer->id,
            'items' => [['menu_item_id' => $biryani->id, 'quantity' => 3]],
            'payment' => ['amount' => '500', 'method' => 'cash'],
        ])->assertCreated()->json('data.id');
        $this->sale = FoodSale::find($saleId);

        $hall = Hall::factory()->create(['branch_id' => $this->branchA->id, 'name' => 'Grand Hall']);
        $bookingId = $this->actingAs($this->cashier)->postJson('/api/v1/restaurant/hall-bookings', [
            'hall_id' => $hall->id, 'customer_id' => $customer->id, 'booking_date' => now()->addDays(5)->toDateString(),
            'start_time' => '18:00', 'end_time' => '22:00', 'agreed_amount' => '30000',
            'payment' => ['amount' => '10000', 'method' => 'bank_transfer', 'reference' => 'TRX-9'],
        ])->assertCreated()->json('data.id');
        $this->booking = HallBooking::find($bookingId);
    }

    private function salePayment(string $amount): FoodSalePayment
    {
        $this->travel(1)->seconds();
        $this->actingAs($this->cashier)->postJson("/api/v1/restaurant/sales/{$this->sale->id}/payments", [
            'payment_date' => now()->toDateString(), 'amount' => $amount, 'method' => 'mobile_banking',
        ])->assertCreated();

        return FoodSalePayment::where('sale_id', $this->sale->id)->latest('created_at')->latest('id')->first();
    }

    public function test_food_sale_receipt_content_and_position_as_of_each_payment(): void
    {
        $first = $this->sale->payments()->sole();
        $reversed = $this->salePayment('100');
        $this->actingAs($this->cashier)->postJson("/api/v1/restaurant/sales/{$this->sale->id}/payments/{$reversed->id}/reverse", ['reason' => 'Wrong amount'])->assertOk();
        $second = $this->salePayment('250');

        $receipt = app(PaymentReceipt::class);
        $data = $receipt->saleReceiptData($this->sale, $first->fresh());

        $this->assertMatchesRegularExpression('/^FR-\d{8}-[0-9A-Z]{6}$/', $data['number']);
        $this->assertSame('Karim Uddin', $data['customer']);
        $this->assertSame('500.00', $data['amount']);
        $this->assertSame('Payment for food sale '.$this->sale->sale_no, $data['purpose']);
        $this->assertSame([['name' => 'Kacchi Biryani', 'quantity' => 3, 'unit_price' => '350.00', 'line_total' => '1,050.00']], $data['items']);
        $this->assertSame(['1,050.00', '500.00', '550.00'], [$data['obligation'], $data['paid_to_date'], $data['outstanding']]);
        $this->assertNull($data['void']);

        // Later receipt: the reversed payment never counts.
        $later = $receipt->saleReceiptData($this->sale, $second->fresh());
        $this->assertSame(['750.00', '300.00'], [$later['paid_to_date'], $later['outstanding']]);

        // The reversed payment prints as VOID and is excluded from its own balance.
        $void = $receipt->saleReceiptData($this->sale, $reversed->fresh());
        $this->assertSame('Wrong amount', $void['void']['reason']);
        $this->assertSame('500.00', $void['paid_to_date']);
    }

    public function test_walk_in_sale_receipt(): void
    {
        $tea = MenuItem::factory()->create(['price_minor' => 2500]);
        $id = $this->actingAs($this->cashier)->postJson('/api/v1/restaurant/sales', [
            'branch_id' => $this->branchA->id, 'items' => [['menu_item_id' => $tea->id, 'quantity' => 2]],
            'payment' => ['amount' => '50', 'method' => 'cash'],
        ])->assertCreated()->json('data.id');
        $sale = FoodSale::find($id);

        $data = app(PaymentReceipt::class)->saleReceiptData($sale, $sale->payments()->sole());
        $this->assertSame('Walk-in customer', $data['customer']);
        $this->assertSame('0.00', $data['outstanding']);
    }

    public function test_hall_booking_receipt_content(): void
    {
        $payment = $this->booking->payments()->sole();
        $data = app(PaymentReceipt::class)->bookingReceiptData($this->booking, $payment);

        $this->assertMatchesRegularExpression('/^BR-\d{8}-[0-9A-Z]{6}$/', $data['number']);
        $this->assertSame('10,000.00', $data['amount']);
        $this->assertStringContainsString('Ten Thousand', $data['amount_words']);
        $this->assertSame('Bank Transfer', (string) $data['method']);
        $this->assertSame('TRX-9', $data['reference']);
        $this->assertSame('Grand Hall', $data['document']['Hall']);
        $this->assertStringContainsString('18:00–22:00', $data['document']['Event']);
        $this->assertSame(['30,000.00', '10,000.00', '20,000.00'], [$data['obligation'], $data['paid_to_date'], $data['outstanding']]);
        $this->assertSame([], $data['items']);
    }

    public function test_receipts_are_pdfs_and_printing_is_audited(): void
    {
        $salePayment = $this->sale->payments()->sole();
        $bookingPayment = $this->booking->payments()->sole();

        $sale = $this->actingAs($this->cashier)->get("/api/v1/restaurant/sales/{$this->sale->id}/payments/{$salePayment->id}/receipt")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $sale->getContent());
        $booking = $this->actingAs($this->cashier)->get("/api/v1/restaurant/hall-bookings/{$this->booking->id}/payments/{$bookingPayment->id}/receipt")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $booking->getContent());

        $logs = AuditLog::where('action', 'restaurant.payment_receipt_printed')->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame(['restaurant_sale_payment', 'restaurant_hall_booking_payment'], $logs->pluck('entity_type')->all());
        $this->assertSame($this->branchA->id, $logs[0]->branch_id);
        $this->assertStringStartsWith('FR-', $logs[0]->new_values['document']);
        $this->assertStringStartsWith('BR-', $logs[1]->new_values['document']);
    }

    public function test_food_sale_receipt_is_a_single_80mm_pos_page_and_booking_receipt_is_a4(): void
    {
        // Many lines with long names: still one continuous page, sized to the content.
        $long = MenuItem::factory()->create(['name' => 'Special Mutton Tehari with extra roast and salad platter', 'price_minor' => 125000]);
        $items = [['menu_item_id' => $long->id, 'quantity' => 2]];
        foreach (MenuItem::factory()->count(14)->create(['price_minor' => 1000]) as $item) {
            $items[] = ['menu_item_id' => $item->id, 'quantity' => 1];
        }
        $saleId = $this->actingAs($this->cashier)->postJson('/api/v1/restaurant/sales', [
            'branch_id' => $this->branchA->id, 'customer_id' => $this->sale->customer_id, 'items' => $items,
            'payment' => ['amount' => '100', 'method' => 'cash'],
        ])->assertCreated()->json('data.id');
        $payment = FoodSale::find($saleId)->payments()->sole();

        $pos = $this->actingAs($this->cashier)->get("/api/v1/restaurant/sales/{$saleId}/payments/{$payment->id}/receipt")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertSame(1, preg_match_all('#/Type /Page(?!s)#', $pos), 'The POS receipt must be exactly one page.');
        preg_match('#/MediaBox \[0\.000 0\.000 ([\d.]+) ([\d.]+)\]#', $pos, $box);
        $boxes = [1 => [$box[1]], 2 => [$box[2]]];
        $this->assertSame('226.770', $boxes[1][0]); // 80 mm roll
        $this->assertGreaterThan(600, (float) $boxes[2][0]); // grows with the content

        $short = app(PaymentReceipt::class)->posHeight(app(PaymentReceipt::class)->saleReceiptData($this->sale, $this->sale->payments()->sole()));
        $this->assertLessThan((float) $boxes[2][0], $short);

        $booking = $this->actingAs($this->cashier)
            ->get("/api/v1/restaurant/hall-bookings/{$this->booking->id}/payments/{$this->booking->payments()->sole()->id}/receipt")->getContent();
        $this->assertStringContainsString('/MediaBox [0.000 0.000 595.280 841.890]', $booking); // A4
    }

    public function test_long_booking_receipt_shrinks_to_fit_one_a4_page(): void
    {
        $scales = [];
        View::creator('restaurant.payment-receipt', function ($view) use (&$scales) {
            $scales[] = $view->getData()['scale'] ?? null;
        });

        // A normal receipt prints at full size on one page.
        $payment = $this->booking->payments()->sole();
        $normal = $this->actingAs($this->cashier)->get("/api/v1/restaurant/hall-bookings/{$this->booking->id}/payments/{$payment->id}/receipt")->getContent();
        $this->assertSame(1, preg_match_all('#/Type /Page(?!s)#', $normal));
        $this->assertSame([1.0], $scales);

        // A long package (many dishes, long notes) would need a second page at full size: it is scaled down instead.
        $items = EventMenuItem::factory()->count(80)
            ->sequence(fn ($s) => ['name' => "Event dish {$s->index} special"])->create();
        $hall = Hall::factory()->create(['branch_id' => $this->branchA->id]);
        $bookingId = $this->actingAs($this->cashier)->postJson('/api/v1/restaurant/hall-bookings', [
            'hall_id' => $hall->id, 'customer_id' => $this->sale->customer_id, 'booking_date' => now()->addDays(9)->toDateString(),
            'start_time' => '18:00', 'end_time' => '22:00', 'hall_charge' => '50000',
            'food_package' => [
                'name' => 'Grand Reception Package', 'guest_count' => 500, 'price_per_head' => '1200',
                'event_menu_item_ids' => $items->pluck('id')->all(), 'notes' => str_repeat('Serve the main course at 8:30 pm sharp. ', 12),
            ],
            'payment' => ['amount' => '100000', 'method' => 'cash'],
        ])->assertCreated()->json('data.id');
        $longPayment = HallBooking::find($bookingId)->payments()->sole();

        $scales = [];
        $long = $this->actingAs($this->cashier)->get("/api/v1/restaurant/hall-bookings/{$bookingId}/payments/{$longPayment->id}/receipt")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertSame(1, preg_match_all('#/Type /Page(?!s)#', $long), 'The receipt must stay on one page.');
        $this->assertStringContainsString('/MediaBox [0.000 0.000 595.280 841.890]', $long); // still A4
        $this->assertGreaterThan(1, count($scales), 'Full size did not fit, so smaller sizes were tried.');
        $this->assertLessThan(1.0, end($scales));
        $this->assertGreaterThanOrEqual(min(PaymentReceipt::PAGE_SCALES), end($scales));

        // Extreme content that cannot fit even at the smallest size still prints (on a second page).
        $data = app(PaymentReceipt::class)->bookingReceiptData(HallBooking::find($bookingId), $longPayment);
        $data['package_items'] = array_fill(0, 300, 'A very long event dish name repeated many times');
        $pdf = app(PaymentReceipt::class)->fittedPage($data);
        $this->assertGreaterThan(1, $pdf->getDomPDF()->getCanvas()->get_page_count());
    }

    public function test_reversed_payment_still_prints(): void
    {
        $payment = $this->booking->payments()->sole();
        $this->actingAs($this->cashier)->postJson("/api/v1/restaurant/hall-bookings/{$this->booking->id}/payments/{$payment->id}/reverse", ['reason' => 'Refunded'])->assertOk();

        $this->actingAs($this->cashier)->get("/api/v1/restaurant/hall-bookings/{$this->booking->id}/payments/{$payment->id}/receipt")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('Refunded', app(PaymentReceipt::class)->bookingReceiptData($this->booking, $payment->fresh())['void']['reason']);
    }

    public function test_receipts_respect_permissions_branch_and_ownership(): void
    {
        $salePayment = $this->sale->payments()->sole();
        $bookingPayment = $this->booking->payments()->sole();
        $saleUrl = "/api/v1/restaurant/sales/{$this->sale->id}/payments/{$salePayment->id}/receipt";
        $bookingUrl = "/api/v1/restaurant/hall-bookings/{$this->booking->id}/payments/{$bookingPayment->id}/receipt";

        $this->app['auth']->forgetGuards();
        $this->getJson($saleUrl)->assertUnauthorized();

        // Sale receipts need sale view; booking receipts need booking view.
        $bookingsOnly = $this->userWith(['restaurant.booking.view'], [$this->branchA]);
        $this->actingAs($bookingsOnly)->getJson($saleUrl)->assertForbidden();
        $this->actingAs($bookingsOnly)->get($bookingUrl)->assertOk();
        $salesOnly = $this->userWith(['restaurant.sale.view'], [$this->branchA]);
        $this->actingAs($salesOnly)->get($saleUrl)->assertOk();
        $this->actingAs($salesOnly)->getJson($bookingUrl)->assertForbidden();

        // Other branch.
        $otherBranch = $this->userWith(['restaurant.sale.view', 'restaurant.booking.view'], [$this->branchB]);
        $this->actingAs($otherBranch)->getJson($saleUrl)->assertForbidden();
        $this->actingAs($otherBranch)->getJson($bookingUrl)->assertForbidden();

        // A payment can only be printed through its own sale/booking.
        $this->actingAs($this->cashier)->getJson("/api/v1/restaurant/sales/{$this->sale->id}/payments/{$bookingPayment->id}/receipt")->assertNotFound();
        $otherSaleId = $this->actingAs($this->cashier)->postJson('/api/v1/restaurant/sales', [
            'branch_id' => $this->branchA->id, 'customer_id' => $this->sale->customer_id,
            'items' => [['menu_item_id' => MenuItem::factory()->create()->id, 'quantity' => 1]],
        ])->json('data.id');
        $this->actingAs($this->cashier)->getJson("/api/v1/restaurant/sales/{$otherSaleId}/payments/{$salePayment->id}/receipt")->assertNotFound();

        $this->assertSame(2, AuditLog::where('action', 'restaurant.payment_receipt_printed')->count());
        $this->assertSame(1, HallBookingPayment::count());
    }
}
