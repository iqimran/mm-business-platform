<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Actions\Roles\CreateRole;
use App\Modules\Administration\Actions\Roles\DeleteRole;
use App\Modules\Administration\Actions\Roles\UpdateRole;
use App\Modules\Administration\Http\Requests\RoleRequest;
use App\Modules\Administration\Http\Resources\RoleResource;
use App\Modules\Identity\Models\Role;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('role.view');

        $roles = Role::query()
            ->with('permissions:id,name')
            ->withCount('users')
            ->orderBy('name')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($roles, RoleResource::class);
    }

    public function store(RoleRequest $request, CreateRole $createRole): JsonResponse
    {
        $role = $createRole->handle($request->user(), $request->validated());

        return ApiResponse::success($this->present($role), 'Role created successfully.', 201);
    }

    public function show(Role $role): JsonResponse
    {
        Gate::authorize('role.view');

        return ApiResponse::success($this->present($role));
    }

    public function update(RoleRequest $request, Role $role, UpdateRole $updateRole): JsonResponse
    {
        $role = $updateRole->handle($request->user(), $role, $request->validated());

        return ApiResponse::success($this->present($role), 'Role updated successfully.');
    }

    public function destroy(Request $request, Role $role, DeleteRole $deleteRole): JsonResponse
    {
        Gate::authorize('role.delete');

        $deleteRole->handle($request->user(), $role);

        return ApiResponse::success(message: 'Role deleted successfully.');
    }

    private function present(Role $role): array
    {
        return RoleResource::make($role->load('permissions:id,name')->loadCount('users'))->resolve();
    }
}
