<?php

namespace App\Modules\Restaurant\Http\Resources;

use App\Modules\Restaurant\Models\MenuItem;
use App\Modules\Shared\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MenuItem
 */
class MenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'is_active' => $this->category->is_active,
            ]),
            'name' => $this->name,
            'description' => $this->description,
            'price' => Money::toDecimal($this->price_minor),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
