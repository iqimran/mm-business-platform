<?php

namespace App\Modules\Restaurant\Enums;

/**
 * Derived from total and active payments; never stored.
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
}
