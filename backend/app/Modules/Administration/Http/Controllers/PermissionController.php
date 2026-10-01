<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Http\Resources\PermissionResource;
use App\Modules\Identity\Models\Permission;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Permissions are defined in code (seeders); they are listed here for role editing.
 */
class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('role.view');

        return ApiResponse::success(PermissionResource::collection(Permission::orderBy('name')->get())->resolve());
    }
}
