<?php

namespace Tests\Feature\Car;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;

class CarLifecycleTest extends CarTestCase
{
    private function moveTo(Car $car, string $status, ?string $reason = null)
    {
        return $this->postJson("/api/v1/cars/{$car->id}/status", array_filter(['status' => $status, 'reason' => $reason]));
    }

    public function test_allowed_manual_transitions_are_audited(): void
    {
        $user = $this->salesA();
        $car = Car::factory()->create(['branch_id' => $this->branchA->id]);

        $this->actingAs($user)->moveTo($car, 'IN_STOCK')->assertOk()->assertJsonPath('data.next_statuses', ['PREPARATION', 'READY_FOR_SALE']);
        $this->actingAs($user)->moveTo($car, 'PREPARATION')->assertOk();
        $this->actingAs($user)->moveTo($car, 'READY_FOR_SALE')->assertOk();
        $this->actingAs($user)->moveTo($car, 'PREPARATION')->assertOk();

        $this->assertSame(CarStatus::Preparation, $car->fresh()->status);
        $this->assertSame(4, AuditLog::where('action', 'car.status_changed')->count());
        $this->actingAs($user)->getJson("/api/v1/cars/{$car->id}")->assertJsonPath('data.next_statuses', ['IN_STOCK', 'READY_FOR_SALE']);
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $user = $this->salesA();
        $car = Car::factory()->create(['branch_id' => $this->branchA->id]);

        $this->actingAs($user)->moveTo($car, 'READY_FOR_SALE')->assertConflict();
        $this->actingAs($user)->moveTo($car, 'SOLD')->assertConflict();
        $this->actingAs($user)->moveTo($car, 'COMPLETED')->assertConflict();
        $this->actingAs($user)->moveTo($car, 'PURCHASED')->assertConflict();
        $this->actingAs($user)->moveTo($car, 'FLYING')->assertJsonValidationErrors('status');

        $this->assertSame(CarStatus::Purchased, $car->fresh()->status);
    }

    public function test_status_change_requires_permission(): void
    {
        $car = Car::factory()->create(['branch_id' => $this->branchA->id]);

        $this->moveTo($car, 'IN_STOCK')->assertUnauthorized();
        $this->actingAs($this->managerA())->moveTo($car, 'IN_STOCK')->assertForbidden();
        $this->assertSame(CarStatus::Purchased, $car->fresh()->status);
    }
}
