<?php

namespace App\Modules\Restaurant\Policies;

use App\Modules\Branch\Policies\BranchScopedPolicy;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\RestaurantExpense;

/**
 * Restaurant expenses: permission AND access to the expense's branch.
 */
class RestaurantExpensePolicy extends BranchScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('restaurant.expense.view');
    }

    public function view(User $user, RestaurantExpense $expense): bool
    {
        return $this->allows($user, 'restaurant.expense.view', $expense);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('restaurant.expense.create');
    }

    public function reverse(User $user, RestaurantExpense $expense): bool
    {
        return $this->allows($user, 'restaurant.expense.reverse', $expense);
    }

    /** Paying a supplier bill. */
    public function recordPayment(User $user, RestaurantExpense $expense): bool
    {
        return $this->allows($user, 'restaurant.supplier_payment.create', $expense);
    }

    public function reversePayment(User $user, RestaurantExpense $expense): bool
    {
        return $this->allows($user, 'restaurant.supplier_payment.reverse', $expense);
    }
}
