<?php

namespace App\Modules\Restaurant\Http\Resources;

use App\Modules\Restaurant\Models\RestaurantExpense;
use App\Modules\Restaurant\Models\RestaurantExpensePayment;
use App\Modules\Restaurant\Services\ExpenseFinancials;
use App\Modules\Shared\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RestaurantExpense
 */
class RestaurantExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch->only('id', 'name', 'code')),
            'category' => $this->whenLoaded('category', fn () => $this->category->only('id', 'name')),
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier?->only('id', 'name')),
            'expense_date' => $this->expense_date->toDateString(),
            'amount' => Money::toDecimal($this->amount_minor),
            'description' => $this->description,
            'reference' => $this->reference,
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder?->only('id', 'name')),
            'created_at' => $this->created_at?->toIso8601String(),
            // Supplier dues (backend-computed): no supplier → always fully paid.
            ...app(ExpenseFinancials::class)->position($this->resource),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn (RestaurantExpensePayment $p) => [
                'id' => $p->id,
                'payment_date' => $p->payment_date->toDateString(),
                'amount' => Money::toDecimal($p->amount_minor),
                'method' => $p->method->value,
                'reference' => $p->reference,
                'notes' => $p->notes,
                'recorded_by' => $p->relationLoaded('recorder') ? $p->recorder?->only('id', 'name') : null,
                'created_at' => $p->created_at?->toIso8601String(),
                'is_reversed' => $p->isReversed(),
                'reversed_at' => $p->reversed_at?->toIso8601String(),
                'reversal_reason' => $p->reversal_reason,
            ])->all()),
            'is_reversed' => $this->isReversed(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversed_by' => $this->whenLoaded('reverser', fn () => $this->reverser?->only('id', 'name')),
            'reversal_reason' => $this->reversal_reason,
        ];
    }
}
