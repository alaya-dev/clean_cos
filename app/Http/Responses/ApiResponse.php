<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /** @param array<string, mixed> $meta */
    public static function success(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /** @param array<string, mixed> $errors
     * @param  array<string, mixed>  $meta
     */
    public static function error(string $code, string $message, int $status, array $errors = [], array $meta = []): JsonResponse
    {
        $payload = ['code' => $code, 'message' => $message];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }
}
