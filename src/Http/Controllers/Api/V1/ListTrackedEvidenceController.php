<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Padosoft\PatentBoxTracker\Models\TrackedEvidence;
use Padosoft\PatentBoxTracker\Models\TrackingSession;

final class ListTrackedEvidenceController extends Controller
{
    public function __invoke(Request $request, TrackingSession $trackingSession): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', 50), 200));
        $query = TrackedEvidence::query()
            ->where('tracking_session_id', $trackingSession->id)
            ->orderByDesc('linked_commit_count')
            ->orderBy('id');

        if (($kind = $request->query('kind')) !== null && is_string($kind) && $kind !== '') {
            $query->where('kind', $kind);
        }

        if (($slug = $request->query('slug')) !== null && is_string($slug) && $slug !== '') {
            $query->where('slug', 'like', '%'.$slug.'%');
        }

        $paginator = $query->paginate($perPage);
        $rows = [];
        foreach ($paginator->items() as $evidence) {
            if (! $evidence instanceof TrackedEvidence) {
                continue;
            }

            $rows[] = [
                'id' => (int) $evidence->id,
                'kind' => (string) $evidence->kind,
                'path' => $evidence->path,
                'slug' => $evidence->slug,
                'title' => $evidence->title,
                'first_seen_at' => $evidence->first_seen_at?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'last_modified_at' => $evidence->last_modified_at?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'linked_commit_count' => (int) ($evidence->linked_commit_count ?? 0),
            ];
        }

        return response()->json([
            'data' => $rows,
            'meta' => [
                'page' => (int) $paginator->currentPage(),
                'per_page' => (int) $paginator->perPage(),
                'total' => (int) $paginator->total(),
            ],
        ]);
    }
}
