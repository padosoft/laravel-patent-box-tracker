<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Padosoft\PatentBoxTracker\Api\ApiResponse;
use Padosoft\PatentBoxTracker\Api\TrackingApiSupport;

final class TrackingDryRunController extends Controller
{
    public function __invoke(Request $request, TrackingApiSupport $support): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'mode' => ['required', 'in:single_repo,cross_repo'],
            'period.from' => ['required', 'date_format:Y-m-d'],
            'period.to' => ['required', 'date_format:Y-m-d'],
            'classifier.provider' => ['nullable', 'string'],
            'classifier.model' => ['nullable', 'string'],
            'repositories' => ['required', 'array', 'min:1'],
            'repositories.*.path' => ['required', 'string', 'min:1', 'distinct'],
            'repositories.*.role' => ['required', 'in:primary_ip,support,meta_self'],
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

        $excludedAuthors = array_values(array_filter((array) config('patent-box-tracker.excluded_authors', []), 'is_string'));
        $rows = [];
        $total = 0;
        foreach ((array) $payload['repositories'] as $repo) {
            $path = (string) $repo['path'];
            $role = (string) $repo['role'];
            try {
                $count = $support->commitCountForWindow($path, $from, $to, $excludedAuthors);
            } catch (\InvalidArgumentException $exception) {
                return ApiResponse::error('invalid_repository', $exception->getMessage(), [], 422);
            }
            $total += $count;
            $rows[] = [
                'path' => $path,
                'role' => $role,
                'commit_count' => $count,
            ];
        }

        $classifier = (array) ($payload['classifier'] ?? []);
        $model = (string) ($classifier['model'] ?? config('patent-box-tracker.classifier.model', 'claude-sonnet-4-6'));
        $projected = $support->projectedCost($total, $model);
        $cap = (float) config('patent-box-tracker.classifier.cost_cap_eur_per_run', 50.0);

        return ApiResponse::success([
            'mode' => (string) $payload['mode'],
            'total_commit_count' => $total,
            'projected_cost_eur' => $projected,
            'cost_cap_eur' => $cap,
            'exceeds_cost_cap' => $projected !== null ? $projected > $cap : false,
            'repositories' => $rows,
        ]);
    }
}
