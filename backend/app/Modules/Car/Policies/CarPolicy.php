<?php

namespace App\Modules\Car\Policies;

use App\Modules\Branch\Policies\BranchScopedPolicy;
use App\Modules\Car\Models\Car;
use App\Modules\Identity\Models\User;

/**
 * Cars require the permission AND access to the car's branch.
 */
class CarPolicy extends BranchScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('car.view');
    }

    public function view(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.view', $car);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('car.create');
    }

    public function update(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.update', $car);
    }

    public function delete(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.delete', $car);
    }

    // Financial records: permission + access to the car's branch.

    public function viewPurchase(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.purchase.view', $car);
    }

    public function recordPurchase(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.purchase.create', $car);
    }

    public function reversePurchase(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.purchase.reverse', $car);
    }

    public function viewExpenses(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.expense.view', $car);
    }

    public function recordExpense(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.expense.create', $car);
    }

    public function reverseExpense(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.expense.reverse', $car);
    }

    // Sale, party (customer) payments, dealer payments and lifecycle.

    public function viewSale(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.sale.view', $car);
    }

    public function recordSale(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.sale.create', $car);
    }

    public function reverseSale(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.sale.reverse', $car);
    }

    public function viewPartyPayments(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.payment.view', $car);
    }

    public function recordPartyPayment(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.payment.create', $car);
    }

    public function reversePartyPayment(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.payment.reverse', $car);
    }

    public function viewDealerPayments(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.dealer_payment.view', $car);
    }

    public function recordDealerPayment(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.dealer_payment.create', $car);
    }

    public function reverseDealerPayment(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.dealer_payment.reverse', $car);
    }

    /** Profit reveals purchase cost and expenses, so it needs all three views. */
    public function viewProfit(User $user, Car $car): bool
    {
        return $this->viewSale($user, $car) && $this->viewPurchase($user, $car) && $this->viewExpenses($user, $car);
    }

    public function changeStatus(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.status.update', $car);
    }

    // Compliance documents (fitness, tax token, insurance, ...).

    public function viewDocuments(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.document.view', $car);
    }

    public function addDocument(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.document.create', $car);
    }

    public function updateDocument(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.document.update', $car);
    }

    public function deleteDocument(User $user, Car $car): bool
    {
        return $this->allows($user, 'car.document.delete', $car);
    }
}
