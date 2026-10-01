<?php

namespace App\Modules\Administration\Http\Resources;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit allow-list: password hashes and tokens are never serialized.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map->only('id', 'name')->values()),
            'branches' => $this->whenLoaded('branches', fn () => $this->branches->map->only('id', 'code', 'name')->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
