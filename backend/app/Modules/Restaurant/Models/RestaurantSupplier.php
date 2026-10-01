<?php

namespace App\Modules\Restaurant\Models;

use App\Modules\Restaurant\Support\Normalize;
use Database\Factories\RestaurantSupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Restaurant supplier (master data only; no purchasing/inventory). Shared by all branches.
 */
#[Fillable(['name', 'contact_person', 'phone', 'address', 'notes', 'is_active'])]
#[UseFactory(RestaurantSupplierFactory::class)]
class RestaurantSupplier extends Model
{
    /** @use HasFactory<RestaurantSupplierFactory> */
    use HasFactory, HasUlids;

    /** Mirrors the column default so new records are complete without a refresh. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Normalize::phone($value));
    }
}
