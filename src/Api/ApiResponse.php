<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Support\Arrayable;

final class ApiResponse
{
    /**
     * @param array|Arrayable<string, mixed> $data
     * @param array<string, mixed>|null $meta
     */
    public static function success(array|Arrayable $data, ?array $meta = null, int $status = 200): JsonResponse
    {
        $payload = [
            'data' => $data instanceof Arrayable ? $data->toArray() : $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(
        string $code,
        string $message,
        array $details = [],
        int $status = 400,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
        ], $status);
    }
}
