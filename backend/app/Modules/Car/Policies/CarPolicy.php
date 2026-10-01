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
}
