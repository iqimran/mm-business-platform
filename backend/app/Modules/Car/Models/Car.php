<?php

namespace App\Modules\Car\Models;

use App\Modules\Branch\Concerns\BelongsToBranch;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Policies\CarPolicy;
use App\Modules\Car\Support\Normalize;
use Database\Factories\CarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Branch-scoped. Status is not mass assignable: it changes only through lifecycle actions.
 */
#[Fillable([
    'branch_id', 'dealer_id', 'brand', 'model', 'model_year', 'color',
    'chassis_number', 'engine_number', 'registration_number', 'mileage_km', 'notes',
])]
#[UseFactory(CarFactory::class)]
#[UsePolicy(CarPolicy::class)]
class Car extends Model
{
    /** @use HasFactory<CarFactory> */
    use BelongsToBranch, HasFactory, HasUlids;

    protected $attributes = [
        'status' => 'PURCHASED',
    ];

    protected function casts(): array
    {
        return [
            'status' => CarStatus::class,
            'model_year' => 'integer',
            'mileage_km' => 'integer',
        ];
    }

    protected function chassisNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Normalize::identifier($value));
    }

    protected function engineNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Normalize::identifier($value));
    }

    protected function registrationNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Normalize::registration($value));
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(CarDealer::class, 'dealer_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(CarImage::class)->orderBy('sort_order')->orderBy('created_at');
    }
}
