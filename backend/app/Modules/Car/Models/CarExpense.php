<?php

namespace App\Modules\Car\Models;

use App\Modules\Car\Concerns\Reversible;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable expense record; correct by reversing and recording a new expense.
 * amount_minor is in minor units (2 decimals).
 */
#[Fillable(['car_id', 'branch_id', 'expense_type_id', 'expense_date', 'amount_minor', 'description', 'reference', 'recorded_by'])]
class CarExpense extends Model
{
    use HasUlids, Reversible;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount_minor' => 'integer',
            'reversed_at' => 'datetime',
        ];
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(CarExpenseType::class, 'expense_type_id');
    }
}
