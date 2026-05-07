<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Validator;
use Padosoft\PatentBoxTracker\Api\ApiResponse;
use Padosoft\PatentBoxTracker\Jobs\RenderTrackingSessionDossierJob;
use Padosoft\PatentBoxTracker\Models\TrackingSession;

final class QueueRenderDossierController extends Controller
{
    public function __invoke(Request $request, int $trackingSession): JsonResponse
    {
        $session = TrackingSession::query()->find((int) $trackingSession);
        if (! $session instanceof TrackingSession) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', [], 404);
        }

        if (! in_array((string) $session->status, [
            TrackingSession::STATUS_CLASSIFIED,
            TrackingSession::STATUS_RENDERED,
        ], true)) {
            return ApiResponse::error('conflict', 'Tracking session must be classified before rendering a dossier.', [], 409);
        }

        $validator = Validator::make($request->all(), [
            'format' => ['required', 'in:pdf,json'],
            'locale' => ['required', 'in:it'],
        ]);
        if ($validator->fails()) {
            return ApiResponse::error('validation_failed', 'The given data was invalid.', $validator->errors()->toArray(), 422);
        }

        $payload = $validator->validated();

        $job = new RenderTrackingSessionDossierJob(
            (int) $session->id,
            (string) $payload['format'],
            (string) $payload['locale'],
        );
        $dispatched = Bus::dispatch($job);
        $jobId = is_string($dispatched) || is_int($dispatched) ? (string) $dispatched : null;

        return ApiResponse::success([
            'tracking_session_id' => (int) $session->id,
            'format' => (string) $job->format,
            'locale' => (string) $job->locale,
            'status' => 'queued',
            'job' => [
                'id' => $jobId,
                'state' => 'queued',
            ],
        ], null, 202);
    }
}
