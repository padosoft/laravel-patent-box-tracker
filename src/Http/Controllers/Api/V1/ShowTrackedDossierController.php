<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Padosoft\PatentBoxTracker\Api\ApiResponse;
use Padosoft\PatentBoxTracker\Models\TrackedDossier;
use Padosoft\PatentBoxTracker\Models\TrackingSession;

final class ShowTrackedDossierController extends Controller
{
    public function __invoke(int $trackingSession, int $dossier): JsonResponse
    {
        $session = TrackingSession::query()->find($trackingSession);
        if (! $session instanceof TrackingSession) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', [], 404);
        }

        $row = TrackedDossier::query()
            ->where('id', $dossier)
            ->where('tracking_session_id', $session->id)
            ->first();
        if (! $row instanceof TrackedDossier || ! is_string($row->path) || $row->path === '') {
            return ApiResponse::error('not_found', 'The requested resource was not found.', [], 404);
        }
        $realPath = realpath($row->path);
        if ($realPath === false || ! is_file($realPath)) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', [], 404);
        }
        $hash = hash_file('sha256', $realPath);
        $size = filesize($realPath);
        if ($hash === false || $size === false) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', [], 404);
        }
        if ($row->sha256 !== null && $row->sha256 !== $hash) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', [], 404);
        }
        if ($row->byte_size !== null && (int) $row->byte_size !== (int) $size) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', [], 404);
        }

        return ApiResponse::success([
            'id' => (int) $row->id,
            'tracking_session_id' => (int) $row->tracking_session_id,
            'format' => (string) $row->format,
            'locale' => (string) $row->locale,
            'path' => $row->path,
            'byte_size' => $row->byte_size !== null ? (int) $row->byte_size : null,
            'sha256' => $row->sha256,
            'generated_at' => $this->iso($row->generated_at),
        ]);
    }

    private function iso(mixed $value): ?string
    {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
