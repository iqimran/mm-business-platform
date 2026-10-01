<?php

namespace App\Modules\Car\Http\Resources;

use App\Modules\Car\Models\CarImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Storage paths are never exposed; files are served through the authorized file endpoint.
 *
 * @mixin CarImage
 */
class CarImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sort_order' => $this->sort_order,
            // Relative to the API base (/api/v1).
            'file_path' => "/cars/{$this->car_id}/images/{$this->id}/file",
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
