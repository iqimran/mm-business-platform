<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Actions\Users\CreateUser;
use App\Modules\Administration\Actions\Users\DeleteUser;
use App\Modules\Administration\Actions\Users\SyncUserBranches;
use App\Modules\Administration\Actions\Users\SyncUserRoles;
use App\Modules\Administration\Actions\Users\UpdateUser;
use App\Modules\Administration\Http\Requests\StoreUserRequest;
use App\Modules\Administration\Http\Requests\SyncUserBranchesRequest;
use App\Modules\Administration\Http\Requests\SyncUserRolesRequest;
use App\Modules\Administration\Http\Requests\UpdateUserRequest;
use App\Modules\Administration\Http\Resources\AdminUserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    private const RELATIONS = ['roles:id,name', 'branches:id,code,name'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'role_id' => ['sometimes', 'string', 'max:26'],
            'branch_id' => ['sometimes', 'string', 'max:26'],
        ]);

        $users = User::query()
            ->visibleTo($request->user())
            ->with(self::RELATIONS)
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', '%'.$search.'%')
                ->orWhere('email', 'ilike', '%'.$search.'%')))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($filters['role_id'] ?? null, fn ($q, $id) => $q->whereHas('roles', fn ($r) => $r->whereKey($id)))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->whereHas('branches', fn ($b) => $b->whereKey($id)))
            ->orderBy('name')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($users, AdminUserResource::class);
    }

    public function store(StoreUserRequest $request, CreateUser $createUser): JsonResponse
    {
        $user = $createUser->handle($request->user(), $request->validated());

        return ApiResponse::success(AdminUserResource::make($user->load(self::RELATIONS))->resolve(), 'User created successfully.', 201);
    }

    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return ApiResponse::success(AdminUserResource::make($user->load(self::RELATIONS))->resolve());
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $updateUser): JsonResponse
    {
        $user = $updateUser->handle($request->user(), $user, $request->validated());

        return ApiResponse::success(AdminUserResource::make($user->load(self::RELATIONS))->resolve(), 'User updated successfully.');
    }

    public function destroy(Request $request, User $user, DeleteUser $deleteUser): JsonResponse
    {
        Gate::authorize('delete', $user);

        $deleteUser->handle($request->user(), $user);

        return ApiResponse::success(message: 'User deleted successfully.');
    }

    public function syncRoles(SyncUserRolesRequest $request, User $user, SyncUserRoles $syncRoles): JsonResponse
    {
        $syncRoles->handle($request->user(), $user, $request->validated('role_ids'));

        return ApiResponse::success(AdminUserResource::make($user->load(self::RELATIONS))->resolve(), 'User roles updated successfully.');
    }

    public function syncBranches(SyncUserBranchesRequest $request, User $user, SyncUserBranches $syncBranches): JsonResponse
    {
        $syncBranches->handle($request->user(), $user, $request->validated('branch_ids'));

        return ApiResponse::success(AdminUserResource::make($user->load(self::RELATIONS))->resolve(), 'User branches updated successfully.');
    }
}
