<?php

namespace App\Modules\Restaurant\Models;

use Database\Factories\EventMenuItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dish offered in hall booking food packages (e.g. Polao, Roast). No price of its own:
 * packages are priced per head. Separate from the food menu; shared by all branches.
 */
#[Table('restaurant_event_menu_items')]
#[Fillable(['name', 'description', 'is_active'])]
#[UseFactory(EventMenuItemFactory::class)]
class EventMenuItem extends Model
{
    /** @use HasFactory<EventMenuItemFactory> */
    use HasFactory, HasUlids;

    /** Mirrors the column default so new records are complete without a refresh. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function packageItems(): HasMany
    {
        return $this->hasMany(HallBookingFoodPackageItem::class, 'event_menu_item_id');
    }
}
