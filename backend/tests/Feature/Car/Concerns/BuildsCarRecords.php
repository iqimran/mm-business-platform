<?php

namespace Tests\Feature\Car\Concerns;

use App\Modules\Branch\Models\Branch;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Enums\PaymentMethod;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarDealer;
use App\Modules\Car\Models\CarDealerPayment;
use App\Modules\Car\Models\CarExpense;
use App\Modules\Car\Models\CarExpenseType;
use App\Modules\Car\Models\CarParty;
use App\Modules\Car\Models\CarPartyPayment;
use App\Modules\Car\Models\CarPurchase;
use App\Modules\Car\Models\CarSale;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Money;

/**
 * Builds financial records directly (bypassing actions) for calculation and report tests.
 */
trait BuildsCarRecords
{
    private ?User $recordsUser = null;

    protected function recorder(): User
    {
        return $this->recordsUser ??= User::factory()->create();
    }

    // Record builders (minor units via decimal strings).

    protected function car(?Branch $branch = null, CarStatus $status = CarStatus::InStock): Car
    {
        $car = Car::factory()->create(['branch_id' => ($branch ?? $this->branchA)->id]);
        $car->forceFill(['status' => $status])->save();

        return $car;
    }

    protected function base(Car $car, string $amount): array
    {
        return ['car_id' => $car->id, 'branch_id' => $car->branch_id, 'amount_minor' => Money::toMinor($amount), 'recorded_by' => $this->recorder()->id];
    }

    protected function purchase(Car $car, string $amount): CarPurchase
    {
        return CarPurchase::create($this->base($car, $amount) + ['dealer_id' => CarDealer::factory()->create()->id, 'purchase_date' => '2026-09-01']);
    }

    protected function expense(Car $car, string $amount, string $date = '2026-09-05', ?CarExpenseType $type = null): CarExpense
    {
        return CarExpense::create($this->base($car, $amount) + [
            'expense_type_id' => ($type ?? CarExpenseType::factory()->create())->id,
            'expense_date' => $date,
        ]);
    }

    protected function sale(Car $car, string $amount, string $date = '2026-09-20'): CarSale
    {
        $sale = CarSale::create($this->base($car, $amount) + [
            'party_id' => CarParty::factory()->create()->id, 'sale_date' => $date, 'status_before_sale' => 'READY_FOR_SALE',
        ]);
        $car->forceFill(['status' => CarStatus::Sold])->save();

        return $sale;
    }

    protected function partyPayment(CarSale $sale, string $amount): CarPartyPayment
    {
        return CarPartyPayment::create($this->base($sale->car, $amount) + [
            'sale_id' => $sale->id, 'party_id' => $sale->party_id, 'payment_date' => '2026-09-21', 'method' => PaymentMethod::Cash,
        ]);
    }

    protected function dealerPayment(CarPurchase $purchase, string $amount): CarDealerPayment
    {
        return CarDealerPayment::create($this->base($purchase->car, $amount) + [
            'purchase_id' => $purchase->id, 'dealer_id' => $purchase->dealer_id, 'payment_date' => '2026-09-02', 'method' => PaymentMethod::Cash,
        ]);
    }

    protected function reverse($record): void
    {
        $record->forceFill(['reversed_at' => now(), 'reversed_by' => $this->recorder()->id, 'reversal_reason' => 'Test reversal'])->save();
    }
}
