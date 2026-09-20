<?php

namespace App\Modules\Attendance\Http;

use Illuminate\Http\JsonResponse;

/** JSON error for the desktop API: a readable message plus a stable code the app can switch on. */
final class ApiError
{
    /** @param array<string, string|int> $headers */
    public static function response(int $status, string $code, string $message, array $headers = []): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status, $headers);
    }
}
