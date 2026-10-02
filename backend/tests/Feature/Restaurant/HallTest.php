<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\Hall;
use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Models\RestaurantCustomer;

class HallTest extends RestaurantTestCase
{
    private const HALLS = '/api/v1/restaurant/halls';

    private function manager(array $branches): User
    {
        return $this->userWith(array_map(fn ($a) => "restaurant.hall.{$a}", ['view', 'create', 'update', 'delete']), $branches);
    }

    public function test_hall_crud_with_audit(): void
    {
        $manager = $this->manager([$this->branchA]);

        $id = $this->actingAs($manager)->postJson(self::HALLS, ['branch_id' => $this->branchA->id, 'name' => ' Grand Hall ', 'capacity' => 300])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Grand Hall')
            ->assertJsonPath('data.capacity', 300)
            ->assertJsonPath('data.branch.id', $this->branchA->id)
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $this->actingAs($manager)->putJson(self::HALLS."/{$id}", ['capacity' => 250, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.capacity', 250)->assertJsonPath('data.is_active', false);
        $this->actingAs($manager)->getJson(self::HALLS.'?is_active=0&search=grand')->assertJsonPath('data.pagination.total', 1);
        $this->actingAs($manager)->deleteJson(self::HALLS."/{$id}")->assertOk();

        $logs = AuditLog::orderBy('created_at')->orderBy('id')->get();
        $this->assertSame(['restaurant_hall.created', 'restaurant_hall.updated', 'restaurant_hall.deleted'], $logs->pluck('action')->all());
        $this->assertSame([$this->branchA->id], $logs->pluck('branch_id')->unique()->values()->all());
    }

    public function test_hall_validation_and_duplicates(): void
    {
        $manager = $this->manager([$this->branchA, $this->branchB]);
        Hall::factory()->create(['branch_id' => $this->branchA->id, 'name' => 'Grand Hall']);

        $this->actingAs($manager)->postJson(self::HALLS, ['branch_id' => $this->branchA->id, 'name' => 'GRAND hall'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name' => 'This branch already has a hall with this name.']);
        // The same name is fine in another branch.
        $this->actingAs($manager)->postJson(self::HALLS, ['branch_id' => $this->branchB->id, 'name' => 'Grand Hall'])->assertCreated();

        $this->actingAs($manager)->postJson(self::HALLS, ['name' => 'X'])->assertJsonValidationErrors('branch_id');
        $this->actingAs($manager)->postJson(self::HALLS, ['branch_id' => $this->branchA->id, 'name' => ' '])->assertJsonValidationErrors('name');
        foreach ([0, -1, 100001, 'many'] as $capacity) {
            $this->actingAs($manager)->postJson(self::HALLS, ['branch_id' => $this->branchA->id, 'name' => 'Y', 'capacity' => $capacity])
                ->assertJsonValidationErrors('capacity');
        }
        $this->assertSame(2, Hall::count());
    }

    public function test_halls_are_branch_scoped(): void
    {
        $hallA = Hall::factory()->create(['branch_id' => $this->branchA->id, 'name' => 'A Hall']);
        $hallB = Hall::factory()->create(['branch_id' => $this->branchB->id, 'name' => 'B Hall']);
        $manager = $this->manager([$this->branchA]);

        $this->actingAs($manager)->getJson(self::HALLS)->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.id', $hallA->id);
        $this->actingAs($manager)->getJson(self::HALLS."/{$hallB->id}")->assertForbidden();
        $this->actingAs($manager)->putJson(self::HALLS."/{$hallB->id}", ['name' => 'Mine'])->assertForbidden();
        $this->actingAs($manager)->deleteJson(self::HALLS."/{$hallB->id}")->assertForbidden();
        $this->actingAs($manager)->postJson(self::HALLS, ['branch_id' => $this->branchB->id, 'name' => 'Sneaky'])->assertJsonValidationErrors('branch_id');
        $this->actingAs($manager)->putJson(self::HALLS."/{$hallA->id}", ['branch_id' => $this->branchB->id])->assertJsonValidationErrors('branch_id');

        $nobody = $this->userWith([], [$this->branchA]);
        $this->actingAs($nobody)->getJson(self::HALLS)->assertForbidden();

        $this->assertSame('B Hall', $hallB->fresh()->name);
        $this->assertSame($this->branchA->id, $hallA->fresh()->branch_id);
    }

    public function test_hall_with_bookings_cannot_be_deleted_or_moved(): void
    {
        $manager = $this->manager([$this->branchA, $this->branchB]);
        $hall = Hall::factory()->create(['branch_id' => $this->branchA->id]);
        HallBooking::create([
            'booking_no' => 'HB-T1', 'branch_id' => $this->branchA->id, 'hall_id' => $hall->id,
            'customer_id' => RestaurantCustomer::factory()->create()->id, 'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '18:00', 'end_time' => '22:00', 'hall_charge_minor' => 100000, 'agreed_amount_minor' => 100000, 'created_by' => $manager->id,
        ]);

        $this->actingAs($manager)->deleteJson(self::HALLS."/{$hall->id}")->assertStatus(409);
        $this->actingAs($manager)->putJson(self::HALLS."/{$hall->id}", ['branch_id' => $this->branchB->id])
            ->assertJsonValidationErrors(['branch_id' => 'This hall has bookings and cannot be moved to another branch.']);
        $this->actingAs($manager)->putJson(self::HALLS."/{$hall->id}", ['is_active' => false])->assertOk();
        $this->assertModelExists($hall);
    }
}
