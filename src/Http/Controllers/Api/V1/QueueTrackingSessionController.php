<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Validator;
use Padosoft\PatentBoxTracker\Api\ApiResponse;
use Padosoft\PatentBoxTracker\Jobs\RunTrackingSessionJob;
use Padosoft\PatentBoxTracker\Models\TrackingSession;

final class QueueTrackingSessionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'mode' => ['required', 'in:single_repo,cross_repo'],
            'tax_identity.denomination' => ['required', 'string', 'min:1'],
            'tax_identity.p_iva' => ['required', 'string', 'min:1'],
            'tax_identity.fiscal_year' => ['required', 'regex:/^\d{4}$/'],
            'tax_identity.regime' => ['required', 'in:documentazione_idonea,non_documentazione'],
            'period.from' => ['required', 'date_format:Y-m-d'],
            'period.to' => ['required', 'date_format:Y-m-d'],
            'classifier.provider' => ['nullable', 'string'],
            'classifier.model' => ['nullable', 'string'],
            'cost_model' => ['nullable', 'array'],
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

        $classifier = (array) ($payload['classifier'] ?? []);
        $driver = (string) ($classifier['provider'] ?? config('patent-box-tracker.classifier.driver', 'regolo'));
        $model = (string) ($classifier['model'] ?? config('patent-box-tracker.classifier.model', 'claude-sonnet-4-6'));

        $seed = config('patent-box-tracker.classifier.seed');
        $session = TrackingSession::query()->create([
            'tax_identity_json' => (array) $payload['tax_identity'],
            'period_from' => (string) $payload['period']['from'].' 00:00:00',
            'period_to' => (string) $payload['period']['to'].' 00:00:00',
            'cost_model_json' => (array) ($payload['cost_model'] ?? []),
            'classifier_provider' => $driver,
            'classifier_model' => $model,
            'classifier_seed' => is_int($seed) ? $seed : 0,
            'status' => TrackingSession::STATUS_QUEUED,
        ]);

        $job = new RunTrackingSessionJob(
            (int) $session->id,
            array_values((array) $payload['repositories']),
            (string) $payload['period']['from'],
            (string) $payload['period']['to'],
            $driver,
            $model,
        );
        $dispatched = Bus::dispatch($job);
        $jobId = is_string($dispatched) || is_int($dispatched) ? (string) $dispatched : null;

        return ApiResponse::success([
            'id' => (int) $session->id,
            'status' => TrackingSession::STATUS_QUEUED,
            'mode' => (string) $payload['mode'],
            'period' => [
                'from' => $from->format('Y-m-d\TH:i:s\Z'),
                'to' => $to->format('Y-m-d\TH:i:s\Z'),
            ],
            'job' => [
                'id' => $jobId,
                'state' => 'queued',
            ],
        ], null, 202);
    }
}
