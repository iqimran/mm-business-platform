<?php

namespace App\Modules\Shared\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

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

    /**
     * @param  class-string<JsonResource>  $resource
     */
    public static function paginated(LengthAwarePaginator $paginator, string $resource, string $message = 'Operation completed successfully.'): JsonResponse
    {
        return self::success([
            'items' => $resource::collection($paginator->getCollection())->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], $message);
    }

    /**
     * Page size from ?per_page, clamped to 1..100.
     */
    public static function perPage(Request $request, int $default = 25): int
    {
        return max(1, min(100, $request->integer('per_page', $default)));
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
