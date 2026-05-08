<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Padosoft\PatentBoxTracker\Api\ApiResponse;
use Padosoft\PatentBoxTracker\Api\TrackingApiSupport;

final class ValidateRepositoryController extends Controller
{
    public function __invoke(Request $request, TrackingApiSupport $support): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'path' => ['required', 'string', 'min:1'],
            'role' => ['required', 'in:primary_ip,support,meta_self'],
            'period.from' => ['required', 'date_format:Y-m-d'],
            'period.to' => ['required', 'date_format:Y-m-d'],
        ]);
        if ($validator->fails()) {
            return ApiResponse::error('validation_failed', 'The given data was invalid.', $validator->errors()->toArray(), 422);
        }
        $payload = $validator->validated();

        $from = new \DateTimeImmutable((string) $payload['period']['from'].'T00:00:00Z');
        $to = new \DateTimeImmutable((string) $payload['period']['to'].'T00:00:00Z');
        if ($from >= $to) {
            return ApiResponse::error('validation_failed', 'The given data was invalid.', [
                'period' => ['period.from must be strictly earlier than period.to.'],
            ], 422);
        }

        $path = (string) $payload['path'];
        $role = (string) $payload['role'];
        try {
            $support->assertRepository($path);
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error('validation_failed', $exception->getMessage(), [], 422);
        }
        $excludedAuthors = array_values(array_filter((array) config('patent-box-tracker.excluded_authors', []), 'is_string'));
        $count = $support->commitCountForWindow($path, $from, $to, $excludedAuthors);

        return ApiResponse::success([
            'path' => $path,
            'is_git_repository' => true,
            'role' => $role,
            'commit_count' => $count,
            'warnings' => [],
        ]);
    }
}
