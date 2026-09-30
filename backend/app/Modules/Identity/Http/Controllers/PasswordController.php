<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\ChangePassword;
use App\Modules\Identity\Actions\ResetPassword;
use App\Modules\Identity\Actions\SendPasswordResetLink;
use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    public function update(ChangePasswordRequest $request, ChangePassword $changePassword): JsonResponse
    {
        $changePassword->handle($request->user(), $request->validated('password'));

        return ApiResponse::success(message: 'Password changed successfully.');
    }

    public function sendResetLink(ForgotPasswordRequest $request, SendPasswordResetLink $sendLink): JsonResponse
    {
        $sendLink->handle($request->validated('email'));

        return ApiResponse::success(message: 'If an account exists for that email, a password reset link has been sent.');
    }

    public function reset(ResetPasswordRequest $request, ResetPassword $resetPassword): JsonResponse
    {
        $resetPassword->handle(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password'),
        );

        return ApiResponse::success(message: 'Password has been reset.');
    }
}
