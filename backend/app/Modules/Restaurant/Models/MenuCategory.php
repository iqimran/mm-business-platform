<?php

namespace App\Modules\Restaurant\Models;

use Database\Factories\MenuCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Food menu category. Shared by all branches.
 */
#[Table('restaurant_menu_categories')]
#[Fillable(['name', 'description', 'sort_order', 'is_active'])]
#[UseFactory(MenuCategoryFactory::class)]
class MenuCategory extends Model
{
    /** @use HasFactory<MenuCategoryFactory> */
    use HasFactory, HasUlids;

    /** Mirrors the column defaults so new records are complete without a refresh. */
    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'category_id');
    }
}
