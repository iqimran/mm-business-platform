<?php

namespace Tests\Feature\Car;

use App\Modules\Administration\Services\BusinessProfiles;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarDealer;
use App\Modules\Car\Models\CarPurchase;
use App\Modules\Car\Services\CarFinancials;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Tests\Feature\Car\Concerns\BuildsCarRecords;

class PaymentSlipTest extends CarTestCase
{
    use BuildsCarRecords;

    private Car $car;

    protected function setUp(): void
    {
        parent::setUp();

        $this->car = $this->car();
    }

    private function viewer(array $extra = []): User
    {
        return $this->userWith(['car.view', 'car.payment.view', 'car.dealer_payment.view', ...$extra], [$this->branchA]);
    }

    public function test_receipt_shows_position_as_of_that_payment(): void
    {
        $this->purchase($this->car, '700000');
        $sale = $this->sale($this->car, '850000');
        $first = $this->partyPayment($sale, '500000');
        $this->travel(1)->seconds();
        $reversed = $this->partyPayment($sale, '100000');
        $this->reverse($reversed);
        $this->travel(1)->seconds();
        $second = $this->partyPayment($sale, '50000');

        $financials = app(CarFinancials::class);

        $this->assertSame([85000000, 50000000, 35000000], array_values($financials->partyPositionAt($first->fresh())));
        // The reversed payment never counts, before or after it.
        $this->assertSame([85000000, 55000000, 30000000], array_values($financials->partyPositionAt($second->fresh())));
    }

    public function test_dealer_voucher_position_as_of_that_payment(): void
    {
        $purchase = $this->purchase($this->car, '700000');
        $first = $this->dealerPayment($purchase, '500000');
        $this->travel(1)->seconds();
        $second = $this->dealerPayment($purchase, '200000');

        $financials = app(CarFinancials::class);
        $this->assertSame(Money::toMinor('200000'), $financials->dealerPositionAt($first->fresh())['outstanding']);
        $this->assertSame(0, $financials->dealerPositionAt($second->fresh())['outstanding']);
    }

    public function test_authorized_user_prints_receipt_and_voucher_and_it_is_audited(): void
    {
        $purchase = $this->purchase($this->car, '700000');
        $payment = $this->partyPayment($this->sale($this->car, '850000'), '500000');
        $dealerPayment = $this->dealerPayment($purchase, '300000');
        $user = $this->viewer();

        $receipt = $this->actingAs($user)->get("/api/v1/cars/{$this->car->id}/party-payments/{$payment->id}/receipt")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $receipt->getContent());
        $this->assertStringContainsString('MR-', $receipt->headers->get('Content-Disposition'));

        $voucher = $this->actingAs($user)->get("/api/v1/cars/{$this->car->id}/dealer-payments/{$dealerPayment->id}/voucher")->assertOk();
        $this->assertStringStartsWith('%PDF', $voucher->getContent());

        $this->assertDatabaseHas('audit_logs', ['action' => 'car.payment_slip_printed', 'entity_id' => $payment->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'car.payment_slip_printed', 'entity_id' => $dealerPayment->id]);
    }

    public function test_slips_use_the_car_business_profile_as_letterhead(): void
    {
        $admin = $this->userWith(['setting.update']);
        $profiles = app(BusinessProfiles::class);
        $profiles->save($admin, 'car', ['name' => 'MM Motors', 'address' => 'Gulshan, Dhaka', 'phone' => '+880 1711-000001']);
        $profiles->save($admin, 'restaurant', ['name' => 'MM Kitchen']);

        $printed = [];
        View::creator('slips.payment-slip', function ($view) use (&$printed) {
            $printed[] = $view->getData();
        });

        $purchase = $this->purchase($this->car, '700000');
        $payment = $this->partyPayment($this->sale($this->car, '850000'), '500000');
        $dealerPayment = $this->dealerPayment($purchase, '300000');
        $this->actingAs($this->viewer())->get("/api/v1/cars/{$this->car->id}/party-payments/{$payment->id}/receipt")->assertOk();
        $this->actingAs($this->viewer())->get("/api/v1/cars/{$this->car->id}/dealer-payments/{$dealerPayment->id}/voucher")->assertOk();

        $this->assertCount(2, $printed);
        foreach ($printed as $data) {
            $this->assertSame('MM Motors', $data['business']);
            $this->assertSame(['Gulshan, Dhaka', 'Phone: +880 1711-000001'], $data['business_lines']);
        }
    }

    public function test_reversed_payment_still_prints_as_void(): void
    {
        $this->purchase($this->car, '700000');
        $payment = $this->partyPayment($this->sale($this->car, '850000'), '500000');
        $this->reverse($payment);

        $this->actingAs($this->viewer())->get("/api/v1/cars/{$this->car->id}/party-payments/{$payment->id}/receipt")->assertOk();
    }

    public function test_slips_respect_permissions_branch_and_ownership(): void
    {
        $purchase = $this->purchase($this->car, '700000');
        $payment = $this->partyPayment($this->sale($this->car, '850000'), '500000');
        $dealerPayment = $this->dealerPayment($purchase, '300000');
        $receiptUrl = "/api/v1/cars/{$this->car->id}/party-payments/{$payment->id}/receipt";
        $voucherUrl = "/api/v1/cars/{$this->car->id}/dealer-payments/{$dealerPayment->id}/voucher";

        $this->getJson($receiptUrl)->assertUnauthorized();

        $noPaymentView = $this->userWith(['car.view', 'car.sale.view'], [$this->branchA]);
        $this->actingAs($noPaymentView)->getJson($receiptUrl)->assertForbidden();
        $this->actingAs($noPaymentView)->getJson($voucherUrl)->assertForbidden();

        $otherBranch = $this->userWith(['car.view', 'car.payment.view', 'car.dealer_payment.view'], [$this->branchB]);
        $this->actingAs($otherBranch)->getJson($receiptUrl)->assertForbidden();

        $otherCar = $this->car();
        $this->actingAs($this->viewer())->getJson("/api/v1/cars/{$otherCar->id}/party-payments/{$payment->id}/receipt")->assertNotFound();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'car.payment_slip_printed']);
    }

    public function test_dates_are_validated_in_the_business_timezone(): void
    {
        // 20:00 UTC on 1 Oct is already 02:00 on 2 Oct in Dhaka: "today" for the user.
        Carbon::setTestNow(Carbon::parse('2026-10-01 20:00:00', 'UTC'));
        $this->car->forceFill(['status' => CarStatus::ReadyForSale])->save();
        CarPurchase::create([
            'car_id' => $this->car->id, 'branch_id' => $this->branchA->id,
            'dealer_id' => CarDealer::factory()->create()->id,
            'purchase_date' => '2026-09-01', 'amount_minor' => 1000000, 'recorded_by' => $this->recorder()->id,
        ]);
        $user = $this->salesA();

        $this->assertSame('Asia/Dhaka', config('app.timezone'));
        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/dealer-payments", [
            'payment_date' => '2026-10-02', 'amount' => '50', 'method' => 'cash',
        ])->assertCreated();

        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/dealer-payments", [
            'payment_date' => '2026-10-03', 'amount' => '10', 'method' => 'cash',
        ])->assertJsonValidationErrors('payment_date');

        Carbon::setTestNow();
    }
}
