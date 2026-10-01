<?php

namespace App\Modules\Restaurant\Enums;

/**
 * Hall booking workflow: confirmed → completed (event held) or confirmed → cancelled (final).
 * Payment status (unpaid/partial/paid) is separate and derived from payments.
 */
enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
