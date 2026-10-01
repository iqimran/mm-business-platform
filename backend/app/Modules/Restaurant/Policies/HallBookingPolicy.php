<?php

namespace App\Modules\Restaurant\Policies;

use App\Modules\Branch\Policies\BranchScopedPolicy;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\HallBooking;

/**
 * Hall bookings and their payments: permission AND access to the booking's branch.
 */
class HallBookingPolicy extends BranchScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('restaurant.booking.view');
    }

    public function view(User $user, HallBooking $booking): bool
    {
        return $this->allows($user, 'restaurant.booking.view', $booking);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('restaurant.booking.create');
    }

    /** Changing details and marking the event completed. */
    public function update(User $user, HallBooking $booking): bool
    {
        return $this->allows($user, 'restaurant.booking.update', $booking);
    }

    public function cancel(User $user, HallBooking $booking): bool
    {
        return $this->allows($user, 'restaurant.booking.cancel', $booking);
    }

    public function recordPayment(User $user, HallBooking $booking): bool
    {
        return $this->allows($user, 'restaurant.booking_payment.create', $booking);
    }

    public function reversePayment(User $user, HallBooking $booking): bool
    {
        return $this->allows($user, 'restaurant.booking_payment.reverse', $booking);
    }
}
