<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Models\Branch;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        // For UI decisions only; every request is still authorized server-side.
        return ApiResponse::success([
            'user' => UserResource::make($user)->resolve(),
            'permissions' => $user->permissionNames()->values(),
            'branches' => Branch::query()
                ->whereIn('id', $user->accessibleBranchIds())
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
    }
}
