<?php

namespace App\Modules\Restaurant\Http\Resources;

use App\Modules\Restaurant\Models\Hall;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Hall
 */
class HallResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch->only('id', 'name', 'code')),
            'name' => $this->name,
            'capacity' => $this->capacity,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
