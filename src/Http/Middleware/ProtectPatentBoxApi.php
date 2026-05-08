<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Padosoft\PatentBoxTracker\Api\ApiResponse;

final class ProtectPatentBoxApi
{
    public function handle(Request $request, Closure $next): mixed
    {
        $expectedToken = (string) config('patent-box-tracker.api.auth_token', '');
        if ($expectedToken === '') {
            return $next($request);
        }

        $providedToken = (string) $request->header('X-Patent-Box-Api-Key', '');
        if ($providedToken === '') {
            $authorization = (string) $request->header('Authorization', '');
            if (str_starts_with($authorization, 'Bearer ')) {
                $providedToken = trim(substr($authorization, 7));
            }
        }

        if (! hash_equals($expectedToken, $providedToken)) {
            return ApiResponse::error(
                'unauthorized',
                'Missing or invalid API token.',
                ['hint' => 'Provide X-Patent-Box-Api-Key header or Authorization Bearer token.'],
                401
            );
        }

        return $next($request);
    }
}
