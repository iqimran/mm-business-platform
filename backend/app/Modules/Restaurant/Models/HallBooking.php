<?php

namespace App\Modules\Restaurant\Models;

use App\Modules\Branch\Concerns\BelongsToBranch;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Enums\BookingStatus;
use App\Modules\Restaurant\Policies\HallBookingPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Hall booking (branch = the hall's branch): hall charge plus an optional event food package.
 * Figures come from BookingFinancials.
 */
#[Table('restaurant_hall_bookings')]
#[Fillable(['booking_no', 'branch_id', 'hall_id', 'customer_id', 'booking_date', 'start_time', 'end_time', 'status', 'hall_charge_minor', 'agreed_amount_minor', 'notes', 'created_by'])]
#[UsePolicy(HallBookingPolicy::class)]
class HallBooking extends Model
{
    use BelongsToBranch, HasUlids;

    protected $attributes = ['status' => 'confirmed'];

    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'status' => BookingStatus::class,
            'hall_charge_minor' => 'integer',
            // Booking total = hall charge + food package total (what payments are measured against).
            'agreed_amount_minor' => 'integer',
            'paid_minor' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }

    public function foodPackage(): HasOne
    {
        return $this->hasOne(HallBookingFoodPackage::class, 'booking_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(RestaurantCustomer::class, 'customer_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(HallBookingPayment::class, 'booking_id')->orderBy('payment_date')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** "18:00" (stored as a time column). */
    public function startsAt(): string
    {
        return substr((string) $this->start_time, 0, 5);
    }

    public function endsAt(): string
    {
        return substr((string) $this->end_time, 0, 5);
    }

    /** SQL for the sum of active payments of the outer booking row. */
    public static function paidSql(): string
    {
        return '(SELECT coalesce(sum(p.amount_minor), 0) FROM restaurant_hall_booking_payments p'
            .' WHERE p.booking_id = restaurant_hall_bookings.id AND p.reversed_at IS NULL)';
    }

    public function scopeWithPaid(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('restaurant_hall_bookings.*');
        }

        return $query->addSelect(DB::raw(self::paidSql().' AS paid_minor'));
    }

    /** Bookings that occupy their hall (anything not cancelled). */
    public function scopeOccupying(Builder $query): Builder
    {
        return $query->where('restaurant_hall_bookings.status', '!=', BookingStatus::Cancelled->value);
    }
}
