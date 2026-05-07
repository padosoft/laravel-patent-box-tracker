<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Controllers\Api\V1;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Padosoft\PatentBoxTracker\Models\TrackedDossier;
use Padosoft\PatentBoxTracker\Models\TrackingSession;

final class DownloadTrackedDossierController extends Controller
{
    public function __invoke(TrackingSession $trackingSession, int $dossier): Response
    {
        $row = TrackedDossier::query()
            ->where('id', $dossier)
            ->where('tracking_session_id', $trackingSession->id)
            ->first();

        if ($row === null || ! is_string($row->path) || $row->path === '') {
            abort(404);
        }

        $real = realpath($row->path);
        if ($real === false || ! is_file($real)) {
            abort(404);
        }

        $bytes = @file_get_contents($real);
        if ($bytes === false) {
            abort(404);
        }

        $contentType = $row->format === 'pdf'
            ? 'application/pdf'
            : 'application/json';

        return response($bytes, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => sprintf('attachment; filename="dossier-%d-%s.%s"', (int) $trackingSession->id, $row->locale, $row->format),
        ]);
    }
}
