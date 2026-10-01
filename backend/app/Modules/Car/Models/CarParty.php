<?php

namespace App\Modules\Car\Models;

use App\Modules\Car\Support\Normalize;
use Database\Factories\CarPartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Customer a car is sold to. Shared across branches.
 */
#[Fillable(['name', 'phone', 'email', 'national_id', 'address', 'notes', 'is_active'])]
#[UseFactory(CarPartyFactory::class)]
class CarParty extends Model
{
    /** @use HasFactory<CarPartyFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Normalize::phone($value));
    }

    protected function nationalId(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Normalize::identifier($value));
    }
}
