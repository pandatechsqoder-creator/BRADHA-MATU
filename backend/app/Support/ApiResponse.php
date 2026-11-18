<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApiResponse
{
    public static function success(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => array_merge([
                'request_id' => app(Request::class)->attributes->get('request_id'),
                'timestamp' => now()->toIso8601String(),
            ], $meta),
        ], $status);
    }

    public static function error(
        string $code,
        string $message,
        int $status,
        array $details = [],
    ): JsonResponse {
        return response()->json([
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details ?: null,
                'request_id' => app(Request::class)->attributes->get('request_id'),
            ], fn ($value) => $value !== null),
        ], $status);
    }
}
