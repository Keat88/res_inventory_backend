<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = 200,
        array $meta = []
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data instanceof JsonResource ? $data->resolve() : $data,
            'meta' => $meta ?: null,
            'errors' => null,
        ], $status);
    }

    public static function created(mixed $data = null, string $message = 'Created successfully'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    public static function error(
        string $message,
        mixed $errors = null,
        int $status = 400,
        ?string $code = null
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'meta' => null,
            'errors' => $code || $errors
                ? ['code' => $code, 'details' => $errors]
                : null,
        ], $status);
    }

    public static function paginated(
        LengthAwarePaginator $paginator,
        mixed $data = null,
        string $message = 'OK'
    ): JsonResponse {
        return self::success(
            $data ?? $paginator->items(),
            $message,
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ]
        );
    }

    public static function noContent(string $message = 'Deleted successfully'): JsonResponse
    {
        return self::success(null, $message, 200);
    }
}
