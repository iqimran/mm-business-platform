<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\Hall;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Models\HallBookingPayment;
use App\Modules\Restaurant\Models\RestaurantCustomer;
use App\Modules\Restaurant\Services\HallAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

class HallBookingTest extends RestaurantTestCase
{
    private const BOOKINGS = '/api/v1/restaurant/hall-bookings';

    private const ALL = [
        'restaurant.booking.view', 'restaurant.booking.create', 'restaurant.booking.update', 'restaurant.booking.cancel',
        'restaurant.booking_payment.create', 'restaurant.booking_payment.reverse',
    ];

    private User $manager;

    private Hall $hall;

    private RestaurantCustomer $customer;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->userWith(self::ALL, [$this->branchA]);
        $this->hall = Hall::factory()->create(['branch_id' => $this->branchA->id, 'name' => 'Grand Hall']);
        $this->customer = RestaurantCustomer::factory()->create(['name' => 'Rahim']);
        $this->date = now()->addDays(7)->toDateString();
    }

    private function book(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->manager)->postJson(self::BOOKINGS, $overrides + [
            'hall_id' => $this->hall->id,
            'customer_id' => $this->customer->id,
            'booking_date' => $this->date,
            'start_time' => '18:00',
            'end_time' => '22:00',
            'agreed_amount' => '50000.00',
        ]);
    }

    private function pay(string $id, string $amount, array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->manager)->postJson(self::BOOKINGS."/{$id}/payments", $overrides + [
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
            'method' => 'cash',
        ]);
    }

    // ---- Creation and validation ---------------------------------------------------------------

    public function test_valid_booking_is_created_unpaid(): void
    {
        $response = $this->book(['notes' => ' Wedding reception '])->assertCreated()
            ->assertJsonPath('data.hall.name', 'Grand Hall')
            ->assertJsonPath('data.branch.id', $this->branchA->id)
            ->assertJsonPath('data.customer.name', 'Rahim')
            ->assertJsonPath('data.booking_date', $this->date)
            ->assertJsonPath('data.start_time', '18:00')
            ->assertJsonPath('data.end_time', '22:00')
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.agreed_amount', '50000.00')
            ->assertJsonPath('data.paid', '0.00')
            ->assertJsonPath('data.due', '50000.00')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.notes', 'Wedding reception');

        $this->assertMatchesRegularExpression('/^HB-\d{6}$/', $response->json('data.booking_no'));
        // The branch always comes from the hall, never from the client.
        $this->book(['branch_id' => $this->branchB->id, 'start_time' => '08:00', 'end_time' => '10:00'])
            ->assertCreated()->assertJsonPath('data.branch.id', $this->branchA->id);

        $created = AuditLog::where('action', 'restaurant.booking.created')->first();
        $this->assertSame($this->branchA->id, $created->branch_id);
        $this->assertSame('50000.00', $created->new_values['agreed_amount']);
    }

    public function test_invalid_bookings_are_rejected(): void
    {
        $inactiveHall = Hall::factory()->create(['branch_id' => $this->branchA->id, 'is_active' => false]);
        $inactiveCustomer = RestaurantCustomer::factory()->create(['is_active' => false]);

        $this->book(['hall_id' => $inactiveHall->id])->assertJsonValidationErrors(['hall_id' => 'Select an active hall.']);
        $this->book(['hall_id' => '01JAAAAAAAAAAAAAAAAAAAAAAA'])->assertJsonValidationErrors('hall_id');
        $this->book(['customer_id' => null])->assertJsonValidationErrors('customer_id');
        $this->book(['customer_id' => $inactiveCustomer->id])->assertJsonValidationErrors(['customer_id' => 'Select an active customer.']);
        $this->book(['booking_date' => now()->subDay()->toDateString()])->assertJsonValidationErrors(['booking_date' => 'The booking date cannot be in the past.']);
        $this->book(['booking_date' => now()->addYears(6)->toDateString()])->assertJsonValidationErrors('booking_date');
        $this->book(['booking_date' => '2026-02-30'])->assertJsonValidationErrors('booking_date');
        $this->book(['start_time' => '22:00', 'end_time' => '18:00'])->assertJsonValidationErrors(['end_time' => 'The end time must be after the start time.']);
        $this->book(['start_time' => '18:00', 'end_time' => '18:00'])->assertJsonValidationErrors('end_time');
        $this->book(['start_time' => '25:00'])->assertJsonValidationErrors('start_time');
        $this->book(['start_time' => '6pm'])->assertJsonValidationErrors('start_time');
        foreach (['0', '-1', 5000.5, '1.234', 'abc'] as $amount) {
            $this->book(['agreed_amount' => $amount])->assertJsonValidationErrors('agreed_amount');
        }
        $this->book(['payment' => ['amount' => '50000.01', 'method' => 'cash']])
            ->assertJsonValidationErrors(['payment.amount' => 'The payment exceeds the agreed amount of 50000.00.']);
        $this->book(['payment' => ['amount' => '100', 'method' => 'gold']])->assertJsonValidationErrors('payment.method');

        $this->assertSame(0, HallBooking::count());
        $this->assertSame(0, HallBookingPayment::count());
    }

    // ---- Availability --------------------------------------------------------------------------

    public function test_double_booking_is_prevented(): void
    {
        $first = $this->book()->assertCreated()->json('data.booking_no');

        foreach ([['17:00', '19:00'], ['21:59', '23:00'], ['19:00', '20:00'], ['17:00', '23:00'], ['18:00', '22:00']] as [$start, $end]) {
            $this->book(['start_time' => $start, 'end_time' => $end])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['start_time' => "The hall is already booked 18:00–22:00 ({$first})."]);
        }

        // Back-to-back slots, another day and another hall are free.
        $this->book(['start_time' => '22:00', 'end_time' => '23:30'])->assertCreated();
        $this->book(['start_time' => '12:00', 'end_time' => '18:00'])->assertCreated();
        $this->book(['booking_date' => now()->addDays(8)->toDateString()])->assertCreated();
        $otherHall = Hall::factory()->create(['branch_id' => $this->branchA->id]);
        $this->book(['hall_id' => $otherHall->id])->assertCreated();

        $this->assertSame(5, HallBooking::count());
    }

    public function test_cancelled_bookings_free_the_hall(): void
    {
        $id = $this->book()->json('data.id');
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$id}/cancel", ['reason' => 'Event postponed'])->assertOk();

        $this->book()->assertCreated();
    }

    public function test_concurrent_booking_race_is_stopped_by_the_database(): void
    {
        $this->book()->assertCreated();

        // Simulate a second request that passed the availability check before the first one committed.
        $this->mock(HallAvailability::class, function ($mock) {
            $mock->shouldReceive('conflicts')->andReturn(collect());
        });

        $this->book(['start_time' => '20:00', 'end_time' => '23:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time' => 'The hall was just booked for an overlapping time. Choose another time.']);

        $this->assertSame(1, HallBooking::count());
        $this->assertSame(1, AuditLog::where('action', 'restaurant.booking.created')->count());
    }

    public function test_availability_endpoint(): void
    {
        $id = $this->book()->json('data.id');
        $this->book(['start_time' => '10:00', 'end_time' => '12:00']);
        $cancelled = $this->book(['start_time' => '13:00', 'end_time' => '14:00'])->json('data.id');
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$cancelled}/cancel", ['reason' => 'Not needed'])->assertOk();

        $this->actingAs($this->manager)->getJson("/api/v1/restaurant/hall-availability?hall_id={$this->hall->id}&date={$this->date}")
            ->assertOk()
            ->assertJsonCount(2, 'data.booked')
            ->assertJsonPath('data.booked.0.start_time', '10:00')
            ->assertJsonPath('data.booked.1.id', $id)
            ->assertJsonMissingPath('data.booked.0.customer');

        $hallB = Hall::factory()->create(['branch_id' => $this->branchB->id]);
        $this->actingAs($this->manager)->getJson("/api/v1/restaurant/hall-availability?hall_id={$hallB->id}&date={$this->date}")->assertNotFound();
        $this->actingAs($this->userWith([], [$this->branchA]))
            ->getJson("/api/v1/restaurant/hall-availability?hall_id={$this->hall->id}&date={$this->date}")->assertForbidden();
    }

    // ---- Payments and due ----------------------------------------------------------------------

    public function test_full_payment_with_the_booking(): void
    {
        $this->book(['payment' => ['amount' => '50000', 'method' => 'bank_transfer', 'reference' => 'TRX-1']])->assertCreated()
            ->assertJsonPath('data.paid', '50000.00')
            ->assertJsonPath('data.due', '0.00')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payments.0.method', 'bank_transfer');
    }

    public function test_partial_payments_and_due_calculation(): void
    {
        $id = $this->book(['payment' => ['amount' => '10000.50', 'method' => 'cash']])->assertCreated()
            ->assertJsonPath('data.paid', '10000.50')
            ->assertJsonPath('data.due', '39999.50')
            ->assertJsonPath('data.payment_status', 'partial')
            ->json('data.id');

        $this->pay($id, '20000')->assertCreated()->assertJsonPath('data.due', '19999.50')->assertJsonPath('data.payment_status', 'partial');
        $this->pay($id, '19999.51')->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'The amount exceeds the remaining due of 19999.50.']);
        $this->pay($id, '19999.50')->assertCreated()
            ->assertJsonPath('data.paid', '50000.00')->assertJsonPath('data.due', '0.00')->assertJsonPath('data.payment_status', 'paid');
        $this->pay($id, '1')->assertStatus(409);

        // Reversing a payment brings the due back.
        $paymentId = HallBookingPayment::orderBy('created_at')->orderBy('id')->first()->id;
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$id}/payments/{$paymentId}/reverse", ['reason' => 'Cheque bounced'])
            ->assertOk()->assertJsonPath('data.due', '10000.50')->assertJsonPath('data.payment_status', 'partial');

        $this->assertSame(
            ['restaurant.booking.created', 'restaurant.booking_payment.recorded', 'restaurant.booking_payment.recorded',
                'restaurant.booking_payment.recorded', 'restaurant.booking_payment.reversed'],
            AuditLog::orderBy('created_at')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_invalid_payments_are_rejected(): void
    {
        $id = $this->book()->json('data.id');

        foreach (['0', '-5', '1.234', 'abc'] as $amount) {
            $this->pay($id, $amount)->assertJsonValidationErrors('amount');
        }
        $this->pay($id, '', ['amount' => 10.5])->assertJsonValidationErrors('amount');
        $this->pay($id, '50000.01')->assertJsonValidationErrors('amount');
        $this->pay($id, '10', ['method' => 'gold'])->assertJsonValidationErrors('method');
        $this->pay($id, '10', ['payment_date' => now()->addDay()->toDateString()])->assertJsonValidationErrors('payment_date');

        $this->assertSame(0, HallBookingPayment::count());
    }

    // ---- Changes and status --------------------------------------------------------------------

    public function test_booking_update_rechecks_availability_and_audits_changes(): void
    {
        $id = $this->book(['payment' => ['amount' => '20000', 'method' => 'cash']])->json('data.id');
        $other = $this->book(['start_time' => '10:00', 'end_time' => '12:00'])->json('data.booking_no');

        $this->actingAs($this->manager)->patchJson(self::BOOKINGS."/{$id}", ['start_time' => '11:00'])
            ->assertJsonValidationErrors(['start_time' => "The hall is already booked 10:00–12:00 ({$other})."]);
        $this->actingAs($this->manager)->patchJson(self::BOOKINGS."/{$id}", ['end_time' => '17:00'])
            ->assertJsonValidationErrors(['end_time' => 'The end time must be after the start time.']);
        $this->actingAs($this->manager)->patchJson(self::BOOKINGS."/{$id}", ['agreed_amount' => '19999.99'])
            ->assertJsonValidationErrors(['agreed_amount' => 'The agreed amount cannot be less than the amount already paid (20000.00).']);
        $this->actingAs($this->manager)->patchJson(self::BOOKINGS."/{$id}", ['booking_date' => now()->subDay()->toDateString()])
            ->assertJsonValidationErrors('booking_date');
        $hallB = Hall::factory()->create(['branch_id' => $this->branchB->id]);
        $this->actingAs($this->manager)->patchJson(self::BOOKINGS."/{$id}", ['hall_id' => $hallB->id])->assertJsonValidationErrors('hall_id');

        $this->actingAs($this->manager)->patchJson(self::BOOKINGS."/{$id}", ['start_time' => '17:00', 'end_time' => '23:00', 'agreed_amount' => '60000'])
            ->assertOk()
            ->assertJsonPath('data.start_time', '17:00')
            ->assertJsonPath('data.end_time', '23:00')
            ->assertJsonPath('data.agreed_amount', '60000.00')
            ->assertJsonPath('data.due', '40000.00');

        $update = AuditLog::where('action', 'restaurant.booking.updated')->sole();
        $this->assertSameValues(['start_time' => '18:00', 'end_time' => '22:00', 'agreed_amount' => '50000.00'], $update->old_values);
        $this->assertSameValues(['start_time' => '17:00', 'end_time' => '23:00', 'agreed_amount' => '60000.00'], $update->new_values);
    }

    public function test_cancellation_rules(): void
    {
        $id = $this->book(['payment' => ['amount' => '5000', 'method' => 'cash']])->json('data.id');
        $paymentId = HallBookingPayment::sole()->id;

        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$id}/cancel", ['reason' => 'no'])->assertJsonValidationErrors('reason');
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$id}/cancel", ['reason' => 'Customer cancelled'])
            ->assertStatus(409)->assertJsonPath('message', 'This booking has payments. Reverse its payments before cancelling.');

        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$id}/payments/{$paymentId}/reverse", ['reason' => 'Refunded to customer'])->assertOk();
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$id}/cancel", ['reason' => 'Customer cancelled'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellation_reason', 'Customer cancelled')
            ->assertJsonPath('data.cancelled_by.id', $this->manager->id);

        // Cancelled bookings are final.
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$id}/cancel", ['reason' => 'Again please'])->assertStatus(409);
        $this->actingAs($this->manager)->patchJson(self::BOOKINGS."/{$id}", ['notes' => 'x'])->assertStatus(409);
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$id}/complete")->assertStatus(409);
        $this->pay($id, '10')->assertStatus(409);

        $cancelled = AuditLog::where('action', 'restaurant.booking.cancelled')->sole();
        $this->assertSame(['status' => 'confirmed'], $cancelled->old_values);
        $this->assertSame('Customer cancelled', $cancelled->new_values['reason']);
    }

    public function test_completion_rules(): void
    {
        $future = $this->book()->json('data.id');
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$future}/complete")
            ->assertStatus(409)->assertJsonPath('message', 'A booking can be completed on or after its event date.');

        $today = $this->book(['booking_date' => now()->toDateString(), 'payment' => ['amount' => '1000', 'method' => 'cash']])->json('data.id');
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$today}/complete")->assertOk()->assertJsonPath('data.status', 'completed');

        // The remaining due can still be collected after the event, but details are locked.
        $this->pay($today, '49000')->assertCreated()->assertJsonPath('data.payment_status', 'paid');
        $this->actingAs($this->manager)->patchJson(self::BOOKINGS."/{$today}", ['agreed_amount' => '60000'])->assertStatus(409);
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$today}/cancel", ['reason' => 'Too late now'])->assertStatus(409);
    }

    // ---- Authorization and branch isolation ----------------------------------------------------

    public function test_authorization(): void
    {
        $id = $this->book(['payment' => ['amount' => '100', 'method' => 'cash']])->json('data.id');
        $paymentId = HallBookingPayment::sole()->id;

        $this->app['auth']->forgetGuards();
        $this->getJson(self::BOOKINGS)->assertUnauthorized();

        $nobody = $this->userWith(['restaurant.hall.view', 'restaurant.sale.view', 'restaurant.sale_payment.create'], [$this->branchA]);
        $this->actingAs($nobody)->getJson(self::BOOKINGS)->assertForbidden();
        $this->actingAs($nobody)->getJson(self::BOOKINGS."/{$id}")->assertForbidden();
        $this->book([], $nobody)->assertForbidden();
        $this->pay($id, '10', [], $nobody)->assertForbidden();

        $viewer = $this->userWith(['restaurant.booking.view'], [$this->branchA]);
        $this->actingAs($viewer)->getJson(self::BOOKINGS."/{$id}")->assertOk();
        $this->book(['start_time' => '08:00', 'end_time' => '09:00'], $viewer)->assertForbidden();
        $this->actingAs($viewer)->patchJson(self::BOOKINGS."/{$id}", ['notes' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->postJson(self::BOOKINGS."/{$id}/complete")->assertForbidden();
        $this->actingAs($viewer)->postJson(self::BOOKINGS."/{$id}/cancel", ['reason' => 'Not allowed'])->assertForbidden();
        $this->pay($id, '10', [], $viewer)->assertForbidden();
        $this->actingAs($viewer)->postJson(self::BOOKINGS."/{$id}/payments/{$paymentId}/reverse", ['reason' => 'Not allowed'])->assertForbidden();

        // Updating does not include cancelling.
        $editor = $this->userWith(['restaurant.booking.view', 'restaurant.booking.update'], [$this->branchA]);
        $this->actingAs($editor)->patchJson(self::BOOKINGS."/{$id}", ['notes' => 'VIP'])->assertOk();
        $this->actingAs($editor)->postJson(self::BOOKINGS."/{$id}/cancel", ['reason' => 'Not allowed'])->assertForbidden();

        $this->assertSame('confirmed', HallBooking::find($id)->status->value);
        $this->assertSame(1, HallBookingPayment::active()->count());
    }

    public function test_branch_isolation(): void
    {
        $idA = $this->book(['payment' => ['amount' => '100', 'method' => 'cash']])->json('data.id');
        $paymentA = HallBookingPayment::sole()->id;
        $hallB = Hall::factory()->create(['branch_id' => $this->branchB->id]);
        $managerB = $this->userWith(self::ALL, [$this->branchB]);
        $idB = $this->book(['hall_id' => $hallB->id], $managerB)->assertCreated()->json('data.id');

        // A hall of an unassigned branch cannot be booked (looks like an unknown hall).
        $this->book(['hall_id' => $hallB->id, 'start_time' => '08:00', 'end_time' => '09:00'])
            ->assertJsonValidationErrors(['hall_id' => 'Select an active hall.']);

        $this->actingAs($this->manager)->getJson(self::BOOKINGS)
            ->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.id', $idA)
            ->assertJsonPath('data.summary.agreed_amount', '50000.00');
        $this->actingAs($this->manager)->getJson(self::BOOKINGS."?branch_id={$this->branchB->id}")->assertJsonPath('data.pagination.total', 0);
        $this->actingAs($this->manager)->getJson(self::BOOKINGS."/{$idB}")->assertForbidden();

        $this->actingAs($managerB)->getJson(self::BOOKINGS."/{$idA}")->assertForbidden();
        $this->actingAs($managerB)->patchJson(self::BOOKINGS."/{$idA}", ['agreed_amount' => '1'])->assertForbidden();
        $this->actingAs($managerB)->postJson(self::BOOKINGS."/{$idA}/cancel", ['reason' => 'Cross branch'])->assertForbidden();
        $this->actingAs($managerB)->postJson(self::BOOKINGS."/{$idA}/complete")->assertForbidden();
        $this->pay($idA, '10', [], $managerB)->assertForbidden();
        $this->actingAs($managerB)->postJson(self::BOOKINGS."/{$idA}/payments/{$paymentA}/reverse", ['reason' => 'Cross branch'])->assertForbidden();
        // A payment can only be reversed through its own booking.
        $this->actingAs($managerB)->postJson(self::BOOKINGS."/{$idB}/payments/{$paymentA}/reverse", ['reason' => 'Wrong booking'])->assertNotFound();

        $global = $this->userWith([...self::ALL, 'branch.access_all']);
        $this->actingAs($global)->getJson(self::BOOKINGS)->assertJsonPath('data.pagination.total', 2);

        $this->assertSame(5000000, HallBooking::find($idA)->agreed_amount_minor);
        $this->assertFalse(HallBookingPayment::find($paymentA)->isReversed());
    }

    // ---- Transactions and database integrity ---------------------------------------------------

    public function test_failed_advance_payment_rolls_back_the_booking(): void
    {
        $audit = app(AuditLogger::class);
        $this->mock(AuditLogger::class, function ($mock) use ($audit) {
            $mock->shouldReceive('record')->andReturnUsing(function (string $action, ...$args) use ($audit) {
                if ($action === 'restaurant.booking_payment.recorded') {
                    throw new \RuntimeException('Simulated failure after the booking was written.');
                }

                return $audit->record($action, ...$args);
            });
        });

        $this->book(['payment' => ['amount' => '100', 'method' => 'cash']])->assertServerError();

        $this->assertSame(0, HallBooking::count());
        $this->assertSame(0, HallBookingPayment::count());
        $this->assertSame(0, AuditLog::count());
        $this->book()->assertCreated(); // the slot is still free
    }

    public function test_database_enforces_booking_integrity(): void
    {
        $id = $this->book(['payment' => ['amount' => '20000', 'method' => 'cash']])->json('data.id');
        $payment = HallBookingPayment::sole();
        $row = fn (array $values) => $values + [
            'id' => strtolower((string) Str::ulid()), 'branch_id' => $this->branchA->id, 'hall_id' => $this->hall->id,
            'customer_id' => $this->customer->id, 'booking_date' => $this->date, 'agreed_amount_minor' => 1000,
            'status' => 'confirmed', 'created_by' => $this->manager->id, 'created_at' => now(), 'updated_at' => now(),
        ];

        // Overlap (exclusion constraint), even when inserted directly; back-to-back is allowed.
        $this->assertRejected(fn () => DB::table('restaurant_hall_bookings')->insert($row(['booking_no' => 'HB-X1', 'start_time' => '21:00', 'end_time' => '23:00'])), 'no_overlap');
        $unpaidId = strtolower((string) Str::ulid());
        DB::table('restaurant_hall_bookings')->insert($row(['id' => $unpaidId, 'booking_no' => 'HB-X2', 'start_time' => '22:00', 'end_time' => '23:00']));
        // The hall must belong to the booking's branch.
        $this->assertRejected(fn () => DB::table('restaurant_hall_bookings')->insert($row(['booking_no' => 'HB-X3', 'branch_id' => $this->branchB->id, 'start_time' => '08:00', 'end_time' => '09:00'])), 'foreign key');
        $this->assertRejected(fn () => DB::table('restaurant_hall_bookings')->insert($row(['booking_no' => 'HB-X4', 'start_time' => '09:00', 'end_time' => '08:00'])), 'time_check');

        // Payments within the agreed amount; agreed amount not below paid; no cancel with payments; no delete.
        $this->assertRejected(fn () => DB::table('restaurant_hall_booking_payments')->insert([
            'id' => strtolower((string) Str::ulid()), 'booking_id' => $id, 'branch_id' => $this->branchA->id, 'payment_date' => now()->toDateString(),
            'amount_minor' => 3000001, 'method' => 'cash', 'recorded_by' => $this->manager->id,
        ]), 'exceed');
        $this->assertRejected(fn () => DB::table('restaurant_hall_bookings')->where('id', $id)->update(['agreed_amount_minor' => 1999999]), 'below the amount paid');
        $this->assertRejected(fn () => DB::table('restaurant_hall_bookings')->where('id', $id)->update([
            'status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $this->manager->id, 'cancellation_reason' => 'Bypass',
        ]), 'active payments');
        $this->assertRejected(fn () => DB::table('restaurant_hall_bookings')->where('id', $id)->delete(), 'immutable');
        $this->assertRejected(fn () => DB::table('restaurant_hall_booking_payments')->where('id', $payment->id)->update(['amount_minor' => 1]), 'immutable');
        // A cancellation must record who, when and why.
        $this->assertRejected(fn () => DB::table('restaurant_hall_bookings')->where('id', $unpaidId)->update(['status' => 'cancelled']), 'cancellation_check');

        $this->assertSame(5000000, HallBooking::find($id)->agreed_amount_minor);
    }

    // ---- Listing -------------------------------------------------------------------------------

    public function test_listing_filters_and_summary(): void
    {
        $paid = $this->book(['payment' => ['amount' => '50000', 'method' => 'cash']])->json('data.id');
        $partial = $this->book(['start_time' => '10:00', 'end_time' => '12:00', 'payment' => ['amount' => '1000', 'method' => 'cash']])->json('data.id');
        $unpaid = $this->book(['booking_date' => now()->addDays(30)->toDateString()])->json('data.id');
        $cancelled = $this->book(['start_time' => '13:00', 'end_time' => '14:00'])->json('data.id');
        $this->actingAs($this->manager)->postJson(self::BOOKINGS."/{$cancelled}/cancel", ['reason' => 'Not needed'])->assertOk();

        $this->actingAs($this->manager)->getJson(self::BOOKINGS)->assertOk()
            ->assertJsonPath('data.pagination.total', 4)
            ->assertJsonPath('data.items.0.id', $unpaid)
            ->assertJsonPath('data.summary.count', 3)
            ->assertJsonPath('data.summary.agreed_amount', '150000.00')
            ->assertJsonPath('data.summary.paid', '51000.00')
            ->assertJsonPath('data.summary.due', '99000.00');

        $ids = fn (string $q) => collect($this->actingAs($this->manager)->getJson(self::BOOKINGS.$q)->assertOk()->json('data.items'))->pluck('id')->all();
        $this->assertSame([$paid], $ids('?payment_status=paid'));
        $this->assertSame([$partial], $ids('?payment_status=partial'));
        $this->assertSame([$unpaid], $ids('?payment_status=unpaid'));
        $this->assertSame([$cancelled], $ids('?status=cancelled'));
        $this->assertSame([$unpaid], $ids('?date_from='.now()->addDays(20)->toDateString()));
        $this->assertCount(4, $ids('?search=rahim'));
        $this->actingAs($this->manager)->getJson(self::BOOKINGS.'?status=pending')->assertUnprocessable();
    }
}
