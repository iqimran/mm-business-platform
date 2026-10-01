<?php

namespace App\Modules\Car\Http\Resources;

use App\Modules\Car\Models\CarDocument;
use App\Modules\Car\Services\DocumentExpiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Status is computed server-side: expired | expiring | valid | superseded (renewed, no alerts).
 * Set the transient "is_current" attribute when listing a car's full history.
 *
 * @mixin CarDocument
 */
class CarDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $current = $this->resource->getAttribute('is_current') ?? true;
        $expiry = app(DocumentExpiry::class)->evaluate($this->resource);

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'name' => $this->displayName(),
            'custom_name' => $this->custom_name,
            'document_number' => $this->document_number,
            'issue_date' => $this->issue_date?->toDateString(),
            'expiry_date' => $this->expiry_date->toDateString(),
            'notes' => $this->notes,
            'is_current' => (bool) $current,
            'status' => $current ? $expiry['status'] : 'superseded',
            'days_remaining' => $expiry['days_remaining'],
            'car' => $this->whenLoaded('car', fn () => [
                'id' => $this->car->id,
                'brand' => $this->car->brand,
                'model' => $this->car->model,
                'chassis_number' => $this->car->chassis_number,
                'registration_number' => $this->car->registration_number,
                'branch' => $this->car->relationLoaded('branch') ? $this->car->branch->only('id', 'code', 'name') : null,
            ]),
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder?->only('id', 'name')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
