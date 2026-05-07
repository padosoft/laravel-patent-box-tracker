<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Controllers\Api\V1;

use Illuminate\Routing\Controller;
use Padosoft\PatentBoxTracker\Models\TrackedDossier;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class DownloadTrackedDossierController extends Controller
{
    public function __invoke(TrackingSession $trackingSession, int $dossier): BinaryFileResponse
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

        $fileHash = hash_file('sha256', $real);
        if ($fileHash === false || $fileHash !== (string) $row->sha256) {
            abort(404);
        }

        $fileSize = filesize($real);
        if ($fileSize === false || $fileSize !== (int) $row->byte_size) {
            abort(404);
        }

        $contentType = $row->format === 'pdf'
            ? 'application/pdf'
            : 'application/json';

        $filename = sprintf('dossier-%d-%s.%s', (int) $trackingSession->id, $row->locale, $row->format);

        return response()->download($real, $filename, ['Content-Type' => $contentType]);
    }
}
