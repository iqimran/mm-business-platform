<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Actions\Settings\UpdateSetting;
use App\Modules\Administration\Http\Requests\UpdateSettingRequest;
use App\Modules\Administration\Http\Resources\SettingResource;
use App\Modules\Administration\Models\Setting;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('setting.view');

        return ApiResponse::success(SettingResource::collection(Setting::orderBy('key')->get())->resolve());
    }

    public function show(string $key): JsonResponse
    {
        Gate::authorize('setting.view');

        return ApiResponse::success(SettingResource::make(Setting::where('key', $key)->firstOrFail())->resolve());
    }

    public function update(UpdateSettingRequest $request, string $key, UpdateSetting $updateSetting): JsonResponse
    {
        $setting = $updateSetting->handle(
            $request->user(),
            $key,
            $request->validated('value'),
            $request->validated('description'),
        );

        return ApiResponse::success(SettingResource::make($setting)->resolve(), 'Setting updated successfully.');
    }
}
