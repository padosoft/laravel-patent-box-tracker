<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Padosoft\PatentBoxTracker\Api\ApiResponse;
use Padosoft\PatentBoxTracker\Models\TrackedDossier;
use Padosoft\PatentBoxTracker\Models\TrackingSession;

final class ListTrackedDossiersController extends Controller
{
    public function __invoke(Request $request, int $trackingSession): JsonResponse
    {
        $session = TrackingSession::query()->find((int) $trackingSession);
        if (! $session instanceof TrackingSession) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', [], 404);
        }

        $perPage = max(1, min((int) $request->query('per_page', '50'), 200));
        $query = TrackedDossier::query()
            ->where('tracking_session_id', $session->id)
            ->orderByDesc('generated_at')
            ->orderByDesc('id');

        if (($format = $request->query('format')) !== null && is_string($format) && $format !== '') {
            $query->where('format', $format);
        }

        if (($locale = $request->query('locale')) !== null && is_string($locale) && $locale !== '') {
            $query->where('locale', $locale);
        }

        $paginator = $query->paginate($perPage);
        $rows = [];
        foreach ($paginator->items() as $dossier) {
            if (! $dossier instanceof TrackedDossier) {
                continue;
            }

            $rows[] = [
                'id' => (int) $dossier->id,
                'tracking_session_id' => (int) $dossier->tracking_session_id,
                'format' => (string) $dossier->format,
                'locale' => (string) $dossier->locale,
                'path' => $dossier->path,
                'byte_size' => $dossier->byte_size !== null ? (int) $dossier->byte_size : null,
                'sha256' => $dossier->sha256,
                'generated_at' => $this->iso($dossier->generated_at),
            ];
        }

        return ApiResponse::success($rows, [
            'page' => (int) $paginator->currentPage(),
            'per_page' => (int) $paginator->perPage(),
            'total' => (int) $paginator->total(),
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
