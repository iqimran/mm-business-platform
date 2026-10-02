<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Models\FoodSalePayment;
use App\Modules\Restaurant\Models\Hall;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Models\HallBookingPayment;
use App\Modules\Restaurant\Models\MenuItem;
use App\Modules\Restaurant\Models\RestaurantCustomer;
use App\Modules\Restaurant\Services\PaymentReceipt;

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
