<?php

namespace App\Modules\Restaurant\Policies;

use App\Modules\Branch\Policies\BranchScopedPolicy;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\FoodSale;

/**
 * Food sales and their payments require the permission AND access to the sale's branch.
 */
class FoodSalePolicy extends BranchScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('restaurant.sale.view');
    }

    public function view(User $user, FoodSale $sale): bool
    {
        return $this->allows($user, 'restaurant.sale.view', $sale);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('restaurant.sale.create');
    }

    public function reverse(User $user, FoodSale $sale): bool
    {
        return $this->allows($user, 'restaurant.sale.reverse', $sale);
    }

    public function recordPayment(User $user, FoodSale $sale): bool
    {
        return $this->allows($user, 'restaurant.sale_payment.create', $sale);
    }

    public function reversePayment(User $user, FoodSale $sale): bool
    {
        return $this->allows($user, 'restaurant.sale_payment.reverse', $sale);
    }
}
