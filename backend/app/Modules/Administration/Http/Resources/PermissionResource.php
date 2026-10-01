<?php

namespace App\Modules\Administration\Http\Resources;

use App\Modules\Identity\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Permission
 */
class PermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            // Module prefix for grouping in the UI, e.g. "car.expense.create" → "car".
            'module' => strtok($this->name, '.'),
        ];
    }
}
