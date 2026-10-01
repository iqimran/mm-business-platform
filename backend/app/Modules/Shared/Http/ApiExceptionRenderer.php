<?php

namespace App\Modules\Shared\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders API errors in the standard contract without leaking internals.
 */
class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error('Validation failed.', 422, $e->errors()),
                $e instanceof AuthenticationException => ApiResponse::error('Unauthenticated.', 401),
                // Authorization messages are authored by us (e.g. privilege-escalation reasons) and safe to show.
                $e instanceof AuthorizationException => ApiResponse::error($e->getMessage() ?: 'This action is unauthorized.', 403),
                $e instanceof AccessDeniedHttpException => ApiResponse::error('This action is unauthorized.', 403),
                $e instanceof ThrottleRequestsException => ApiResponse::error('Too many requests. Please try again later.', 429, headers: $e->getHeaders()),
                $e instanceof NotFoundHttpException => ApiResponse::error('Resource not found.', 404),
                $e instanceof HttpExceptionInterface => ApiResponse::error($e->getMessage() ?: 'Request could not be processed.', $e->getStatusCode(), headers: $e->getHeaders()),
                // Debug mode keeps Laravel's detailed output for local development only.
                config('app.debug') => null,
                default => ApiResponse::error('Server error.', 500),
            };
        });
    }
}
