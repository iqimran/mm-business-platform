<?php

namespace App\Modules\Car\Models;

use App\Modules\Car\Support\Normalize;
use Database\Factories\CarDealerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Supplier a car is purchased from. Shared across branches.
 */
#[Fillable(['name', 'phone', 'email', 'address', 'notes', 'is_active'])]
#[UseFactory(CarDealerFactory::class)]
class CarDealer extends Model
{
    /** @use HasFactory<CarDealerFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Normalize::phone($value));
    }

    public function cars(): HasMany
    {
        return $this->hasMany(Car::class, 'dealer_id');
    }
}
