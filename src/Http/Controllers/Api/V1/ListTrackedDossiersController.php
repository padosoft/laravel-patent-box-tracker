<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Padosoft\PatentBoxTracker\Models\TrackedDossier;
use Padosoft\PatentBoxTracker\Models\TrackingSession;

final class ListTrackedDossiersController extends Controller
{
    public function __invoke(TrackingSession $trackingSession): JsonResponse
    {
        $rows = TrackedDossier::query()
            ->where('tracking_session_id', $trackingSession->id)
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (TrackedDossier $dossier): array => [
                'id' => (int) $dossier->id,
                'tracking_session_id' => (int) $dossier->tracking_session_id,
                'format' => (string) $dossier->format,
                'locale' => (string) $dossier->locale,
                'path' => $dossier->path,
                'byte_size' => $dossier->byte_size !== null ? (int) $dossier->byte_size : null,
                'sha256' => $dossier->sha256,
                'generated_at' => $dossier->generated_at?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ])
            ->all();

        return response()->json([
            'data' => $rows,
        ]);
    }
}
