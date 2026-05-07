<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http;

use Illuminate\Http\JsonResponse;
use Padosoft\PatentBoxTracker\Api\ApiResponse;

final class ApiErrorResponse
{
    /**
     * @param  array<string,mixed>  $details
     */
    public static function make(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return ApiResponse::error($code, $message, $details, $status);
    }
}
