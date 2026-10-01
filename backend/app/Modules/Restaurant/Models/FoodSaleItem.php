<?php

namespace App\Modules\Restaurant\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sale line. Name and unit price are a snapshot of the menu at the time of sale (fully immutable).
 */
#[Table('restaurant_sale_items')]
#[Fillable(['sale_id', 'menu_item_id', 'item_name', 'unit_price_minor', 'quantity', 'line_total_minor'])]
class FoodSaleItem extends Model
{
    use HasUlids;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'quantity' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(FoodSale::class, 'sale_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }
}
