<?php

namespace App\Modules\Shared\Http;

use Illuminate\Http\JsonResponse;

/**
 * Standard API response contract (docs/04-api.md).
 */
class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'Operation completed successfully.', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data ?? (object) [],
        ], $status);
    }

    public static function error(string $message, int $status, array $errors = [], array $headers = []): JsonResponse
    {
        $body = ['success' => false, 'message' => $message];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status, $headers);
    }
}
