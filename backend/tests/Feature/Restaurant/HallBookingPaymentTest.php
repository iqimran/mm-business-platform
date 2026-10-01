<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\Hall;
use App\Modules\Restaurant\Models\HallBookingPayment;
use App\Modules\Restaurant\Models\RestaurantCustomer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * Hall booking payments: Due = agreed amount − active payments; immutable history with reversal.
 */
class HallBookingPaymentTest extends RestaurantTestCase
{
    private const BOOKINGS = '/api/v1/restaurant/hall-bookings';

    private const ALL = [
        'restaurant.booking.view', 'restaurant.booking.create', 'restaurant.booking.update', 'restaurant.booking.cancel',
        'restaurant.booking_payment.create', 'restaurant.booking_payment.reverse',
    ];

    private User $manager;

    private string $bookingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->userWith(self::ALL, [$this->branchA]);
        $hall = Hall::factory()->create(['branch_id' => $this->branchA->id]);

        $this->bookingId = $this->actingAs($this->manager)->postJson(self::BOOKINGS, [
            'hall_id' => $hall->id,
            'customer_id' => RestaurantCustomer::factory()->create()->id,
            'booking_date' => now()->toDateString(),
            'start_time' => '18:00',
            'end_time' => '22:00',
            'agreed_amount' => '30000.00',
        ])->assertCreated()->json('data.id');
    }

    private function pay(string $amount, array $overrides = [], ?User $as = null, ?string $bookingId = null): TestResponse
    {
        return $this->actingAs($as ?? $this->manager)->postJson(self::BOOKINGS.'/'.($bookingId ?? $this->bookingId).'/payments', $overrides + [
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
            'method' => 'cash',
        ]);
    }

    private function reverse(string $paymentId, string $reason = 'Customer refund', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->manager)->postJson(self::BOOKINGS."/{$this->bookingId}/payments/{$paymentId}/reverse", ['reason' => $reason]);
    }

    private function position(): array
    {
        return $this->actingAs($this->manager)->getJson(self::BOOKINGS."/{$this->bookingId}")->assertOk()
            ->json('data');
    }

    // ---- States and due ------------------------------------------------------------------------

    public function test_unpaid_booking_has_full_due(): void
    {
        $data = $this->position();

        $this->assertSame(['30000.00', '0.00', '30000.00', 'unpaid'], [$data['agreed_amount'], $data['paid'], $data['due'], $data['payment_status']]);
        $this->assertSame([], $data['payments']);
    }

    public function test_zero_and_negative_payments_are_rejected(): void
    {
        foreach (['0', '0.00', '-1', '-0.01'] as $amount) {
            $this->pay($amount)->assertUnprocessable()->assertJsonValidationErrors('amount');
        }

        $this->assertSame(0, HallBookingPayment::count());
        $this->assertRejected(fn () => DB::table('restaurant_hall_booking_payments')->insert($this->rawPayment(0)), 'amount_check');
        $this->assertRejected(fn () => DB::table('restaurant_hall_booking_payments')->insert($this->rawPayment(-100)), 'amount_check');
    }

    public function test_partial_payment(): void
    {
        $this->pay('12000.50', ['reference' => 'BK-1', 'notes' => 'Advance'])->assertCreated()
            ->assertJsonPath('data.paid', '12000.50')
            ->assertJsonPath('data.due', '17999.50')
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.payments.0.amount', '12000.50')
            ->assertJsonPath('data.payments.0.reference', 'BK-1')
            ->assertJsonPath('data.payments.0.recorded_by.id', $this->manager->id);
    }

    public function test_full_payment_in_one_go(): void
    {
        $this->pay('30000')->assertCreated()
            ->assertJsonPath('data.paid', '30000.00')
            ->assertJsonPath('data.due', '0.00')
            ->assertJsonPath('data.payment_status', 'paid');

        $this->pay('0.01')->assertStatus(409)->assertJsonPath('message', 'This booking is already fully paid.');
    }

    public function test_multiple_partial_payments_recalculate_the_due(): void
    {
        $steps = [
            ['5000', '5000.00', '25000.00', 'partial'],
            ['0.50', '5000.50', '24999.50', 'partial'],
            ['14999.50', '20000.00', '10000.00', 'partial'],
            ['10000', '30000.00', '0.00', 'paid'],
        ];

        foreach ($steps as [$amount, $paid, $due, $status]) {
            $this->pay($amount, ['method' => 'mobile_banking'])->assertCreated()
                ->assertJsonPath('data.paid', $paid)
                ->assertJsonPath('data.due', $due)
                ->assertJsonPath('data.payment_status', $status);
        }

        $this->assertCount(4, $this->position()['payments']);
        $this->assertSame(3000000, (int) HallBookingPayment::active()->sum('amount_minor'));
    }

    public function test_overpayment_is_rejected(): void
    {
        $this->pay('30000.01')->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'The amount exceeds the remaining due of 30000.00.']);

        // Cumulative overpayment.
        $this->pay('25000')->assertCreated();
        $this->pay('5000.01')->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'The amount exceeds the remaining due of 5000.00.']);

        $this->assertSame(2500000, (int) HallBookingPayment::sum('amount_minor'));
        // The database refuses it too, even if the application check were bypassed.
        $this->assertRejected(fn () => DB::table('restaurant_hall_booking_payments')->insert($this->rawPayment(500001)), 'exceed');
    }

    public function test_invalid_payment_input_is_rejected(): void
    {
        $cases = [
            [['amount' => 100.5], 'amount'],            // float
            [['amount' => '1.234'], 'amount'],
            [['amount' => '1,000'], 'amount'],
            [['amount' => 'abc'], 'amount'],
            [['amount' => ''], 'amount'],
            [['method' => 'gold'], 'method'],
            [['method' => ''], 'method'],
            [['payment_date' => ''], 'payment_date'],
            [['payment_date' => '2026-02-30'], 'payment_date'],
            [['payment_date' => now()->addDay()->toDateString()], 'payment_date'],
            [['reference' => str_repeat('x', 101)], 'reference'],
        ];

        foreach ($cases as [$override, $field]) {
            $this->pay('100', $override)->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->pay('100', ['payment_date' => now()->subDay()->toDateString()])
            ->assertJsonValidationErrors(['payment_date' => 'The payment date cannot be before the booking was made.']);

        $this->assertSame(0, HallBookingPayment::count());
    }

    public function test_invalid_booking_reference(): void
    {
        $this->pay('100', bookingId: '01JAAAAAAAAAAAAAAAAAAAAAAA')->assertNotFound()->assertJsonPath('message', 'Resource not found.');
        $this->pay('100', bookingId: 'not-a-booking')->assertNotFound();

        // A payment can only be reversed through its own booking.
        $this->pay('100')->assertCreated();
        $payment = HallBookingPayment::sole();
        $otherHall = Hall::factory()->create(['branch_id' => $this->branchA->id]);
        $other = $this->actingAs($this->manager)->postJson(self::BOOKINGS, [
            'hall_id' => $otherHall->id, 'customer_id' => RestaurantCustomer::factory()->create()->id,
            'booking_date' => now()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'agreed_amount' => '100',
        ])->json('data.id');
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$other}/payments/{$payment->id}/reverse", ['reason' => 'Wrong booking'])->assertNotFound();
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$this->bookingId}/payments/01JAAAAAAAAAAAAAAAAAAAAAAA/reverse", ['reason' => 'Unknown payment'])->assertNotFound();

        $this->assertFalse($payment->fresh()->isReversed());
    }

    // ---- Booking state -------------------------------------------------------------------------

    public function test_cancelled_booking_cannot_receive_payments(): void
    {
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$this->bookingId}/cancel", ['reason' => 'Event cancelled'])->assertOk();

        $this->pay('100')->assertStatus(409)->assertJsonPath('message', 'This booking is cancelled and cannot receive payments.');
        $this->assertSame(0, HallBookingPayment::count());
        $this->assertRejected(fn () => DB::table('restaurant_hall_booking_payments')->insert($this->rawPayment(100)), 'cancelled');
    }

    public function test_completed_booking_can_still_collect_its_due(): void
    {
        $this->pay('10000')->assertCreated();
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$this->bookingId}/complete")->assertOk();

        $this->pay('20000')->assertCreated()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.payment_status', 'paid');
        $this->pay('1')->assertStatus(409);
    }

    // ---- History integrity: reversal, never rewrite --------------------------------------------

    public function test_reversal_recalculates_the_due_and_keeps_history(): void
    {
        $this->pay('10000')->assertCreated();
        $this->pay('20000')->assertCreated()->assertJsonPath('data.payment_status', 'paid');
        $second = HallBookingPayment::where('amount_minor', 2000000)->sole();

        $this->reverse($second->id, 'no')->assertJsonValidationErrors('reason');
        $this->reverse($second->id, 'Cheque bounced')->assertOk()
            ->assertJsonPath('data.paid', '10000.00')
            ->assertJsonPath('data.due', '20000.00')
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.payments.1.is_reversed', true)
            ->assertJsonPath('data.payments.1.reversal_reason', 'Cheque bounced')
            ->assertJsonPath('data.payments.1.amount', '20000.00');
        $this->reverse($second->id, 'Again please')->assertStatus(409);

        // The due can be paid again after a reversal.
        $this->pay('20000')->assertCreated()->assertJsonPath('data.payment_status', 'paid');

        // Payments are never rewritten or deleted.
        $this->assertRejected(fn () => DB::table('restaurant_hall_booking_payments')->where('id', $second->id)->update(['amount_minor' => 1]), 'already reversed');
        $first = HallBookingPayment::where('amount_minor', 1000000)->sole();
        $this->assertRejected(fn () => DB::table('restaurant_hall_booking_payments')->where('id', $first->id)->update(['amount_minor' => 1]), 'immutable');
        $this->assertRejected(fn () => DB::table('restaurant_hall_booking_payments')->where('id', $first->id)->delete(), 'immutable');
        $this->assertSame(3, HallBookingPayment::count());
    }

    public function test_payment_audit_records_status_transitions(): void
    {
        $this->pay('10000')->assertCreated();
        $this->pay('20000')->assertCreated();
        $this->reverse(HallBookingPayment::where('amount_minor', 2000000)->sole()->id, 'Cheque bounced')->assertOk();

        $logs = AuditLog::where('entity_type', 'restaurant_hall_booking_payment')->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame(
            ['restaurant.booking_payment.recorded', 'restaurant.booking_payment.recorded', 'restaurant.booking_payment.reversed'],
            $logs->pluck('action')->all(),
        );
        $this->assertSame(['unpaid', 'partial', '10000.00', '20000.00'], [
            $logs[0]->new_values['payment_status_before'], $logs[0]->new_values['payment_status_after'],
            $logs[0]->new_values['paid_after'], $logs[0]->new_values['due_after'],
        ]);
        $this->assertSame(['partial', 'paid', '0.00'], [
            $logs[1]->new_values['payment_status_before'], $logs[1]->new_values['payment_status_after'], $logs[1]->new_values['due_after'],
        ]);
        $this->assertSame(['paid', 'partial', '20000.00', 'Cheque bounced'], [
            $logs[2]->new_values['payment_status_before'], $logs[2]->new_values['payment_status_after'],
            $logs[2]->new_values['due_after'], $logs[2]->new_values['reason'],
        ]);
        $this->assertSame('20000.00', $logs[2]->old_values['amount']);
        $this->assertSame([$this->branchA->id], $logs->pluck('branch_id')->unique()->values()->all());
        $this->assertSame([$this->manager->id], $logs->pluck('user_id')->unique()->values()->all());
    }

    // ---- Authorization and branches ------------------------------------------------------------

    public function test_unauthorized_payments_are_rejected(): void
    {
        $this->pay('100')->assertCreated();
        $paymentId = HallBookingPayment::sole()->id;

        $this->app['auth']->forgetGuards();
        $this->postJson(self::BOOKINGS."/{$this->bookingId}/payments", ['payment_date' => now()->toDateString(), 'amount' => '1', 'method' => 'cash'])
            ->assertUnauthorized();

        // Booking management and food-sale payment permissions do not allow booking payments.
        $noPayment = $this->userWith(['restaurant.booking.view', 'restaurant.booking.update', 'restaurant.sale_payment.create', 'restaurant.sale_payment.reverse'], [$this->branchA]);
        $this->pay('100', [], $noPayment)->assertForbidden();
        $this->reverse($paymentId, 'Not allowed', $noPayment)->assertForbidden();

        // Recording does not include reversing.
        $collector = $this->userWith(['restaurant.booking_payment.create'], [$this->branchA]);
        $this->pay('100', [], $collector)->assertCreated();
        $this->reverse($paymentId, 'Not allowed', $collector)->assertForbidden();

        $inactive = $this->userWith(self::ALL, [$this->branchA]);
        $inactive->update(['is_active' => false]);
        // Deactivated users are signed out by the "active" middleware.
        $this->pay('100', [], $inactive)->assertUnauthorized();

        $this->assertSame(2, HallBookingPayment::active()->count());
    }

    public function test_cross_branch_payments_are_rejected(): void
    {
        $this->pay('100')->assertCreated();
        $paymentId = HallBookingPayment::sole()->id;

        $otherBranch = $this->userWith(self::ALL, [$this->branchB]);
        $this->pay('100', [], $otherBranch)->assertForbidden();
        $this->reverse($paymentId, 'Cross branch', $otherBranch)->assertForbidden();

        // Losing access to a branch (deactivated branch) removes payment rights there.
        $this->branchA->update(['is_active' => false]);
        $this->pay('100', [], $this->userWith(self::ALL, [$this->branchA]))->assertForbidden();
        $this->branchA->update(['is_active' => true]);

        // Global access works across branches.
        $this->pay('100', [], $this->userWith([...self::ALL, 'branch.access_all']))->assertCreated();

        // A payment row must carry its booking's branch.
        $this->assertRejected(fn () => DB::table('restaurant_hall_booking_payments')->insert(['branch_id' => $this->branchB->id] + $this->rawPayment(100)), 'foreign key');

        $this->assertSame(2, HallBookingPayment::active()->count());
        $this->assertSame([$this->branchA->id], HallBookingPayment::pluck('branch_id')->unique()->values()->all());
    }

    // ---- Transactions --------------------------------------------------------------------------

    public function test_failed_payment_is_rolled_back(): void
    {
        $audit = app(AuditLogger::class);
        $this->mock(AuditLogger::class, function ($mock) use ($audit) {
            $mock->shouldReceive('record')->andReturnUsing(function (string $action, ...$args) use ($audit) {
                if ($action === 'restaurant.booking_payment.recorded') {
                    throw new RuntimeException('Simulated failure after the payment row was written.');
                }

                return $audit->record($action, ...$args);
            });
        });

        $this->pay('10000')->assertServerError();

        $this->assertSame(0, HallBookingPayment::count());
        $this->assertSame(0, AuditLog::where('entity_type', 'restaurant_hall_booking_payment')->count());
        $this->assertSame('30000.00', $this->position()['due']);
    }

    public function test_failed_reversal_is_rolled_back(): void
    {
        $this->pay('10000')->assertCreated();
        $payment = HallBookingPayment::sole();

        $audit = app(AuditLogger::class);
        $this->mock(AuditLogger::class, function ($mock) use ($audit) {
            $mock->shouldReceive('record')->andReturnUsing(function (string $action, ...$args) use ($audit) {
                if ($action === 'restaurant.booking_payment.reversed') {
                    throw new RuntimeException('Simulated failure after the reversal was written.');
                }

                return $audit->record($action, ...$args);
            });
        });

        $this->reverse($payment->id)->assertServerError();

        $this->assertFalse($payment->fresh()->isReversed());
        $this->assertSame('20000.00', $this->position()['due']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawPayment(int $amountMinor): array
    {
        return [
            'id' => strtolower((string) Str::ulid()),
            'booking_id' => $this->bookingId,
            'branch_id' => $this->branchA->id,
            'payment_date' => now()->toDateString(),
            'amount_minor' => $amountMinor,
            'method' => 'cash',
            'recorded_by' => $this->manager->id,
        ];
    }
}
