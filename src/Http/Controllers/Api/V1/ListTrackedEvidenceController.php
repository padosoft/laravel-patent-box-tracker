<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Padosoft\PatentBoxTracker\Api\ApiResponse;
use Padosoft\PatentBoxTracker\Models\TrackedEvidence;
use Padosoft\PatentBoxTracker\Models\TrackingSession;

final class ListTrackedEvidenceController extends Controller
{
    public function __invoke(Request $request, int $trackingSession): JsonResponse
    {
        $session = TrackingSession::query()->find((int) $trackingSession);
        if (! $session instanceof TrackingSession) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', [], 404);
        }

        $perPage = max(1, min((int) $request->query('per_page', '50'), 200));
        $query = TrackedEvidence::query()
            ->where('tracking_session_id', $session->id)
            ->orderByDesc('linked_commit_count')
            ->orderBy('id');

        if (($kind = $request->query('kind')) !== null && is_string($kind) && $kind !== '') {
            $query->where('kind', $kind);
        }

        if (($slug = $request->query('slug')) !== null && is_string($slug) && $slug !== '') {
            $query->where('slug', 'like', '%'.$slug.'%');
        }

        if (($pathLike = $request->query('path_like')) !== null && is_string($pathLike) && $pathLike !== '') {
            $query->where('path', 'like', '%'.$pathLike.'%');
        }

        if (($search = $request->query('search')) !== null && is_string($search) && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('slug', 'like', '%'.$search.'%')
                    ->orWhere('path', 'like', '%'.$search.'%')
                    ->orWhere('title', 'like', '%'.$search.'%');
            });
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
                'first_seen_at' => $this->iso($evidence->first_seen_at),
                'last_modified_at' => $this->iso($evidence->last_modified_at),
                'linked_commit_count' => (int) ($evidence->linked_commit_count ?? 0),
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
