<?php

namespace App\Modules\Restaurant\Models;

use App\Modules\Branch\Concerns\BelongsToBranch;
use App\Modules\Restaurant\Policies\HallPolicy;
use Database\Factories\HallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bookable hall of a branch (branch-scoped master data).
 */
#[Table('restaurant_halls')]
#[Fillable(['branch_id', 'name', 'capacity', 'description', 'is_active'])]
#[UseFactory(HallFactory::class)]
#[UsePolicy(HallPolicy::class)]
class Hall extends Model
{
    /** @use HasFactory<HallFactory> */
    use BelongsToBranch, HasFactory, HasUlids;

    /** Mirrors the column default so new records are complete without a refresh. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'capacity' => 'integer'];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(HallBooking::class, 'hall_id');
    }
}
