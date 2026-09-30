<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\LoginUser;
use App\Modules\Identity\Actions\LogoutUser;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthenticatedSessionController extends Controller
{
    public function store(LoginRequest $request, LoginUser $login): JsonResponse
    {
        $user = $login->handle($request, $request->validated('email'), $request->validated('password'));

        return ApiResponse::success(['user' => UserResource::make($user)->resolve()], 'Logged in successfully.');
    }

    public function destroy(Request $request, LogoutUser $logout): JsonResponse
    {
        $logout->handle($request);

        return ApiResponse::success(message: 'Logged out successfully.');
    }
}
