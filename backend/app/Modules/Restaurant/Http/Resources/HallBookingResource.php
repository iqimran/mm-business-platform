<?php

namespace App\Modules\Restaurant\Http\Resources;

use App\Modules\Restaurant\Models\HallBooking;
use App\Modules\Restaurant\Models\HallBookingPayment;
use App\Modules\Restaurant\Services\BookingFinancials;
use App\Modules\Shared\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Hall booking with backend-computed figures: hall charge, event food package, booking total,
 * paid, due and payment_status.
 *
 * @mixin HallBooking
 */
class HallBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_no' => $this->booking_no,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch->only('id', 'name', 'code')),
            'hall' => $this->whenLoaded('hall', fn () => $this->hall->only('id', 'name', 'capacity', 'is_active')),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer->only('id', 'name', 'phone')),
            'booking_date' => $this->booking_date->toDateString(),
            'start_time' => $this->startsAt(),
            'end_time' => $this->endsAt(),
            'status' => $this->status->value,
            'hall_charge' => Money::toDecimal($this->hall_charge_minor),
            'food_package' => $this->whenLoaded('foodPackage', fn () => $this->foodPackage === null ? null : [
                'id' => $this->foodPackage->id,
                'name' => $this->foodPackage->name,
                'guest_count' => $this->foodPackage->guest_count,
                'price_per_head' => Money::toDecimal($this->foodPackage->price_per_head_minor),
                'total' => Money::toDecimal($this->foodPackage->total_minor),
                'notes' => $this->foodPackage->notes,
                'items' => $this->foodPackage->relationLoaded('items')
                    ? $this->foodPackage->items->map(fn ($item) => ['event_menu_item_id' => $item->event_menu_item_id, 'item_name' => $item->item_name])->values()->all()
                    : null,
            ]),
            ...app(BookingFinancials::class)->position($this->resource),
            'notes' => $this->notes,
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn (HallBookingPayment $p) => [
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
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->only('id', 'name')),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->whenLoaded('canceller', fn () => $this->canceller?->only('id', 'name')),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
