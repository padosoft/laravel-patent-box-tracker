<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Padosoft\PatentBoxTracker\Api\ApiResponse;
use Padosoft\PatentBoxTracker\Hash\HashChainBuilder;
use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackingSession;

final class VerifySessionIntegrityController extends Controller
{
    public function __invoke(int $trackingSession, HashChainBuilder $hashChainBuilder): JsonResponse
    {
        $session = TrackingSession::query()->find((int) $trackingSession);
        if (! $session instanceof TrackingSession) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', [], 404);
        }

        $commits = TrackedCommit::query()
            ->where('tracking_session_id', $session->id)
            ->orderBy('committed_at')
            ->orderBy('id')
            ->get();

        $manifest = [];
        foreach ($commits as $commit) {
            if (! $commit instanceof TrackedCommit) {
                continue;
            }

            $manifest[] = [
                'sha' => (string) $commit->sha,
                'prev' => $commit->hash_chain_prev,
                'self' => (string) ($commit->hash_chain_self ?? ''),
            ];
        }

        $verified = $hashChainBuilder->verify($manifest);

        $firstFailure = null;
        if (! $verified) {
            $firstFailure = $this->firstFailure($manifest, $hashChainBuilder);
        }

        $head = $commits->last()?->hash_chain_self;
        if (! is_string($head) || $head === '') {
            $head = hash('sha256', '');
        }

        return ApiResponse::success([
            'verified' => $verified,
            'head' => $head,
            'commit_count' => $commits->count(),
            'first_failure' => $firstFailure,
        ]);
    }

    /**
     * @param  list<array{sha:string,prev:string|null,self:string}>  $manifest
     */
    private function firstFailure(array $manifest, HashChainBuilder $hashChainBuilder): ?int
    {
        $expectedPrev = null;
        foreach ($manifest as $idx => $row) {
            if ($row['prev'] !== $expectedPrev) {
                return $idx;
            }

            $computed = $hashChainBuilder->link($row['prev'], $row['sha']);
            if (! hash_equals($computed, $row['self'])) {
                return $idx;
            }

            $expectedPrev = $row['self'];
        }

        return null;
    }
}
