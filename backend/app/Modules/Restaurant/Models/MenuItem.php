<?php

namespace App\Modules\Restaurant\Models;

use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Food menu item with a global selling price (integer minor units). Shared by all branches.
 */
#[Table('restaurant_menu_items')]
#[Fillable(['category_id', 'name', 'description', 'price_minor', 'is_active'])]
#[UseFactory(MenuItemFactory::class)]
class MenuItem extends Model
{
    /** @use HasFactory<MenuItemFactory> */
    use HasFactory, HasUlids;

    /** Mirrors the column default so new records are complete without a refresh. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'price_minor' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }
}
