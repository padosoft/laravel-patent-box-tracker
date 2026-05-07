<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Padosoft\PatentBoxTracker\Api\ApiResponse;
use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackingSession;

final class ListTrackedCommitsController extends Controller
{
    public function __invoke(Request $request, int $trackingSession): JsonResponse
    {
        $session = TrackingSession::query()->find((int) $trackingSession);
        if (! $session instanceof TrackingSession) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', [], 404);
        }

        $perPage = max(1, min((int) $request->query('per_page', 50), 200));
        $query = TrackedCommit::query()
            ->where('tracking_session_id', $session->id)
            ->orderByDesc('committed_at')
            ->orderByDesc('id');

        if (($phase = $request->query('phase')) !== null && is_string($phase) && $phase !== '') {
            $query->where('phase', $phase);
        }

        if (($repositoryPath = $request->query('repository_path')) !== null && is_string($repositoryPath) && $repositoryPath !== '') {
            $query->where('repository_path', $repositoryPath);
        }

        if (($qualified = $request->query('is_rd_qualified')) !== null && is_string($qualified) && $qualified !== '') {
            $qualifiedValue = filter_var($qualified, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($qualifiedValue !== null) {
                $query->where('is_rd_qualified', $qualifiedValue);
            }
        }

        if (($aiAttribution = $request->query('ai_attribution')) !== null && is_string($aiAttribution) && $aiAttribution !== '') {
            $query->where('ai_attribution', $aiAttribution);
        }

        if (($confidenceMin = $request->query('rd_confidence_min')) !== null && is_string($confidenceMin) && $confidenceMin !== '') {
            $confidenceMinValue = filter_var($confidenceMin, FILTER_VALIDATE_FLOAT);
            if ($confidenceMinValue !== false) {
                $query->where('rd_qualification_confidence', '>=', $confidenceMinValue);
            }
        }

        if (($confidenceMax = $request->query('rd_confidence_max')) !== null && is_string($confidenceMax) && $confidenceMax !== '') {
            $confidenceMaxValue = filter_var($confidenceMax, FILTER_VALIDATE_FLOAT);
            if ($confidenceMaxValue !== false) {
                $query->where('rd_qualification_confidence', '<=', $confidenceMaxValue);
            }
        }

        if (($search = $request->query('search')) !== null && is_string($search) && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('sha', 'like', '%'.$search.'%')
                    ->orWhere('message', 'like', '%'.$search.'%')
                    ->orWhere('rationale', 'like', '%'.$search.'%');
            });
        }

        $paginator = $query->paginate($perPage);
        $rows = [];
        foreach ($paginator->items() as $commit) {
            if (! $commit instanceof TrackedCommit) {
                continue;
            }

            $sha = (string) $commit->sha;
            $rows[] = [
                'id' => (int) $commit->id,
                'sha' => $sha,
                'short_sha' => substr($sha, 0, 7),
                'repository_path' => (string) $commit->repository_path,
                'repository_role' => $commit->repository_role,
                'author_name' => $commit->author_name,
                'author_email' => $commit->author_email,
                'committed_at' => $commit->committed_at?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'message_subject' => $this->subject((string) ($commit->message ?? '')),
                'files_changed_count' => $commit->files_changed_count !== null ? (int) $commit->files_changed_count : null,
                'insertions' => $commit->insertions !== null ? (int) $commit->insertions : null,
                'deletions' => $commit->deletions !== null ? (int) $commit->deletions : null,
                'phase' => $commit->phase,
                'is_rd_qualified' => $commit->is_rd_qualified !== null ? (bool) $commit->is_rd_qualified : null,
                'rd_qualification_confidence' => $commit->rd_qualification_confidence !== null ? (float) $commit->rd_qualification_confidence : null,
                'rationale' => $commit->rationale,
                'rejected_phase' => $commit->rejected_phase,
                'evidence_used' => (array) ($commit->evidence_used_json ?? []),
                'ai_attribution' => $commit->ai_attribution,
                'branch_name_canonical' => $commit->branch_name_canonical,
                'hash_chain' => [
                    'prev' => $commit->hash_chain_prev,
                    'self' => $commit->hash_chain_self,
                ],
            ];
        }

        return ApiResponse::success($rows, [
            'page' => (int) $paginator->currentPage(),
            'per_page' => (int) $paginator->perPage(),
            'total' => (int) $paginator->total(),
        ]);
    }

    private function subject(string $message): string
    {
        $line = strtok($message, "\n");

        return $line === false ? '' : trim($line);
    }
}
