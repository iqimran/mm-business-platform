<?php

namespace App\Modules\Restaurant\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Event menu item in a hall booking food package. The name is copied when the item is added, so later menu
 * changes do not alter what was agreed.
 */
#[Table('restaurant_hall_booking_food_package_items')]
#[Fillable(['package_id', 'event_menu_item_id', 'item_name', 'sort_order'])]
class HallBookingFoodPackageItem extends Model
{
    use HasUlids;

    const UPDATED_AT = null;
}
