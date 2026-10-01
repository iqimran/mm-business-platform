<?php

namespace App\Modules\Restaurant\Models;

use App\Modules\Restaurant\Concerns\Reversible;
use App\Modules\Restaurant\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received against a hall booking (advance or settlement). Immutable apart from reversal.
 */
#[Table('restaurant_hall_booking_payments')]
#[Fillable(['booking_id', 'branch_id', 'payment_date', 'amount_minor', 'method', 'reference', 'notes', 'recorded_by'])]
class HallBookingPayment extends Model
{
    use HasUlids, Reversible;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount_minor' => 'integer',
            'method' => PaymentMethod::class,
            'reversed_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(HallBooking::class, 'booking_id');
    }
}
