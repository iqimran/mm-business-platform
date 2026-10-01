<?php

namespace App\Modules\Identity\Concerns;

use App\Modules\Identity\Models\Permission;
use Illuminate\Support\Collection;

/**
 * Permission evaluation: User → Roles → Permissions.
 * Never checks role names. Resolved once per model instance (i.e. per request).
 */
trait HasPermissions
{
    private ?Collection $resolvedPermissions = null;

    /**
     * @return Collection<int, string>
     */
    public function permissionNames(): Collection
    {
        return $this->is_active ? $this->grantedPermissionNames() : collect();
    }

    /**
     * Permissions granted through roles regardless of account status.
     * Used for privilege comparisons (an inactive admin still outranks a manager).
     *
     * @return Collection<int, string>
     */
    public function grantedPermissionNames(): Collection
    {
        return $this->resolvedPermissions ??= Permission::query()
            ->join('role_permission', 'role_permission.permission_id', '=', 'permissions.id')
            ->join('role_user', 'role_user.role_id', '=', 'role_permission.role_id')
            ->where('role_user.user_id', $this->getKey())
            ->distinct()
            ->orderBy('permissions.name')
            ->pluck('permissions.name');
    }

    public function hasPermission(string $permission): bool
    {
        return $this->permissionNames()->contains($permission);
    }

    public function forgetResolvedPermissions(): void
    {
        $this->resolvedPermissions = null;
    }
}
