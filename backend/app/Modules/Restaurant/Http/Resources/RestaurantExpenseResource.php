<?php

namespace App\Modules\Restaurant\Http\Resources;

use App\Modules\Restaurant\Models\RestaurantExpense;
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
            'is_reversed' => $this->isReversed(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversed_by' => $this->whenLoaded('reverser', fn () => $this->reverser?->only('id', 'name')),
            'reversal_reason' => $this->reversal_reason,
        ];
    }
}
