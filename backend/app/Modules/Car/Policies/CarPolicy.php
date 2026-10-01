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
