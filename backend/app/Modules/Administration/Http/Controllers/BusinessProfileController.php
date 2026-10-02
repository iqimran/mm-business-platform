<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Http\Requests\BusinessProfileRequest;
use App\Modules\Administration\Services\BusinessProfiles;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Car and Restaurant business profiles (letterhead of printed documents and exports).
 * Same permissions as settings; changes are audited as setting updates.
 */
class BusinessProfileController extends Controller
{
    public function __construct(private readonly BusinessProfiles $profiles) {}

    public function index(): JsonResponse
    {
        Gate::authorize('setting.view');

        return ApiResponse::success(array_map(fn ($module) => $this->profiles->get($module), array_keys(BusinessProfiles::MODULES)));
    }

    public function show(string $module): JsonResponse
    {
        Gate::authorize('setting.view');

        return ApiResponse::success($this->profiles->get($module));
    }

    public function update(BusinessProfileRequest $request, string $module): JsonResponse
    {
        $profile = $this->profiles->save($request->user(), $module, $request->validated());

        return ApiResponse::success($profile, BusinessProfiles::MODULES[$module].' profile saved successfully.');
    }
}
