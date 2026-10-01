<?php

namespace App\Modules\Restaurant\Http\Resources;

use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Models\FoodSaleItem;
use App\Modules\Restaurant\Models\FoodSalePayment;
use App\Modules\Restaurant\Services\SaleFinancials;
use App\Modules\Shared\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Food sale with backend-computed figures (total, paid, due, payment_status). Amounts are decimal strings.
 *
 * @mixin FoodSale
 */
class FoodSaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_no' => $this->sale_no,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch->only('id', 'name', 'code')),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer?->only('id', 'name', 'phone')),
            'sold_at' => $this->sold_at->toIso8601String(),
            'notes' => $this->notes,
            ...app(SaleFinancials::class)->position($this->resource),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (FoodSaleItem $item) => [
                'id' => $item->id,
                'menu_item_id' => $item->menu_item_id,
                'item_name' => $item->item_name,
                'unit_price' => Money::toDecimal($item->unit_price_minor),
                'quantity' => $item->quantity,
                'line_total' => Money::toDecimal($item->line_total_minor),
            ])->all()),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn (FoodSalePayment $p) => [
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
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder?->only('id', 'name')),
            'created_at' => $this->created_at?->toIso8601String(),
            'is_reversed' => $this->isReversed(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversed_by' => $this->whenLoaded('reverser', fn () => $this->reverser?->only('id', 'name')),
            'reversal_reason' => $this->reversal_reason,
        ];
    }
}
