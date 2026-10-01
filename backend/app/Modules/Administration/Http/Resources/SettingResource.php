<?php

namespace App\Modules\Administration\Http\Resources;

use App\Modules\Administration\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Setting
 */
class SettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'description' => $this->description,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
