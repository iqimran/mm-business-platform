<?php

namespace App\Modules\Car\Http\Resources;

use App\Modules\Car\Models\CarExpense;
use App\Modules\Car\Models\CarPurchase;
use App\Modules\Shared\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Purchases and expenses. Amounts are decimal strings; reversed records stay visible.
 *
 * @mixin CarPurchase|CarExpense
 */
class FinancialRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $common = [
            'id' => $this->id,
            'amount' => Money::toDecimal($this->amount_minor),
            'reference' => $this->reference,
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder?->only('id', 'name')),
            'created_at' => $this->created_at?->toIso8601String(),
            'is_reversed' => $this->isReversed(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversed_by' => $this->whenLoaded('reverser', fn () => $this->reverser?->only('id', 'name')),
            'reversal_reason' => $this->reversal_reason,
        ];

        if ($this->resource instanceof CarPurchase) {
            return [
                'id' => $this->id,
                'dealer' => $this->whenLoaded('dealer', fn () => $this->dealer->only('id', 'name')),
                'purchase_date' => $this->purchase_date->toDateString(),
                'notes' => $this->notes,
            ] + $common;
        }

        return [
            'id' => $this->id,
            'expense_type' => $this->whenLoaded('expenseType', fn () => $this->expenseType->only('id', 'name')),
            'expense_date' => $this->expense_date->toDateString(),
            'description' => $this->description,
        ] + $common;
    }
}
