<?php

namespace App\Modules\Car\Models;

use Database\Factories\CarExpenseTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Category of car expense (e.g. "Paint", "Tyres"). Shared across branches.
 */
#[Fillable(['name', 'description', 'is_active'])]
#[UseFactory(CarExpenseTypeFactory::class)]
class CarExpenseType extends Model
{
    /** @use HasFactory<CarExpenseTypeFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
