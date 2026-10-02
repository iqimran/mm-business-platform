<?php

namespace App\Modules\Restaurant\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Event food package of a hall booking: billable guests × price per head (not a food sale).
 * Same branch as its booking; changeable only while the booking is confirmed.
 */
#[Table('restaurant_hall_booking_food_packages')]
#[Fillable(['booking_id', 'branch_id', 'name', 'guest_count', 'price_per_head_minor', 'total_minor', 'notes', 'created_by'])]
class HallBookingFoodPackage extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'guest_count' => 'integer',
            'price_per_head_minor' => 'integer',
            'total_minor' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(HallBooking::class, 'booking_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(HallBookingFoodPackageItem::class, 'package_id')->orderBy('sort_order')->orderBy('id');
    }
}
