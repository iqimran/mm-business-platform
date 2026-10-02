<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Services\ApplicationName;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public, non-sensitive application branding (shown on the login page before sign-in).
 * Exposes only the display name; nothing else from settings.
 */
class AppInfoController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(['name' => ApplicationName::get()]);
    }
}
