<?php

namespace App\Modules\Car\Http\Resources;

use App\Modules\Car\Models\Car;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Car
 */
class CarResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch->only('id', 'code', 'name')),
            'dealer' => $this->whenLoaded('dealer', fn () => $this->dealer?->only('id', 'name')),
            'brand' => $this->brand,
            'model' => $this->model,
            'model_year' => $this->model_year,
            'color' => $this->color,
            'chassis_number' => $this->chassis_number,
            'engine_number' => $this->engine_number,
            'registration_number' => $this->registration_number,
            'mileage_km' => $this->mileage_km,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'images_count' => $this->whenCounted('images'),
            'images' => CarImageResource::collection($this->whenLoaded('images')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
