<?php

namespace Tests\Feature\Car;

use App\Modules\Branch\Models\Branch;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarDealer;
use App\Modules\Car\Models\CarPurchase;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class CarTestCase extends TestCase
{
    use RefreshDatabase;

    protected const CAR_PERMISSIONS = ['car.view', 'car.create', 'car.update', 'car.delete'];

    protected Branch $branchA;

    protected Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->branchA = Branch::factory()->create(['code' => 'A']);
        $this->branchB = Branch::factory()->create(['code' => 'B']);
    }

    /**
     * @param  array<string>  $permissions
     * @param  array<Branch>  $branches
     */
    protected function userWith(array $permissions, array $branches = []): User
    {
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::whereIn('name', $permissions)->pluck('id'));

        $user = User::factory()->create();
        $user->roles()->attach($role);
        $user->branches()->attach(collect($branches)->pluck('id'));

        return $user;
    }

    protected const FINANCE_PERMISSIONS = [
        'car.view', 'car.purchase.view', 'car.purchase.create', 'car.purchase.reverse',
        'car.expense.view', 'car.expense.create', 'car.expense.reverse',
    ];

    protected const SALES_PERMISSIONS = [
        'car.view', 'car.purchase.view', 'car.purchase.reverse', 'car.expense.view', 'car.expense.create',
        'car.sale.view', 'car.sale.create', 'car.sale.reverse',
        'car.payment.view', 'car.payment.create', 'car.payment.reverse',
        'car.dealer_payment.view', 'car.dealer_payment.create', 'car.dealer_payment.reverse',
        'car.status.update',
    ];

    /** Full sales/payments user for branch A only. */
    protected function salesA(): User
    {
        return $this->userWith(self::SALES_PERMISSIONS, [$this->branchA]);
    }

    /**
     * A car in branch A with an active purchase, in the given status.
     */
    protected function purchasedCar(string $amount = '700000', CarStatus $status = CarStatus::ReadyForSale, ?Branch $branch = null): Car
    {
        $car = Car::factory()->create(['branch_id' => ($branch ?? $this->branchA)->id]);
        $car->forceFill(['status' => $status])->save();
        CarPurchase::create([
            'car_id' => $car->id, 'branch_id' => $car->branch_id,
            'dealer_id' => CarDealer::factory()->create()->id,
            'purchase_date' => '2026-09-01', 'amount_minor' => Money::toMinor($amount),
            'recorded_by' => User::factory()->create()->id,
        ]);

        return $car;
    }

    /** Car finance user for branch A only. */
    protected function financeA(): User
    {
        return $this->userWith(self::FINANCE_PERMISSIONS, [$this->branchA]);
    }

    /** Car manager for branch A only. */
    protected function managerA(array $extra = []): User
    {
        return $this->userWith([...self::CAR_PERMISSIONS, ...$extra], [$this->branchA]);
    }
}
