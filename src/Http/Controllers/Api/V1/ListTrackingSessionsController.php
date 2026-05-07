<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Padosoft\PatentBoxTracker\Api\ApiResponse;
use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackingSession;

final class ListTrackingSessionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', 25), 100));

        $query = TrackingSession::query()->orderByDesc('id');

        if (($status = $request->query('status')) !== null && is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        if (($fiscalYear = $request->query('fiscal_year')) !== null && is_string($fiscalYear) && $fiscalYear !== '') {
            $query->where('tax_identity_json->fiscal_year', $fiscalYear);
        }

        if (($regime = $request->query('regime')) !== null && is_string($regime) && $regime !== '') {
            $query->where('tax_identity_json->regime', $regime);
        }

        if (($from = $request->query('from')) !== null && is_string($from) && $from !== '') {
            $query->whereDate('period_from', '>=', $from);
        }

        if (($to = $request->query('to')) !== null && is_string($to) && $to !== '') {
            $query->whereDate('period_to', '<=', $to);
        }

        if (($search = $request->query('search')) !== null && is_string($search) && trim($search) !== '') {
            $id = (int) trim($search);
            if ((string) $id === trim($search)) {
                $query->where('id', $id);
            }
        }

        $paginator = $query->paginate($perPage);
        $rows = [];
        $sessionIds = [];
        foreach ($paginator->items() as $session) {
            if ($session instanceof TrackingSession) {
                $sessionIds[] = (int) $session->id;
            }
        }
        $summaries = $this->summariesFor($sessionIds);

        foreach ($paginator->items() as $session) {
            if (! $session instanceof TrackingSession) {
                continue;
            }

            $taxIdentity = (array) ($session->tax_identity_json ?? []);
            $summary = $summaries[(int) $session->id] ?? [
                'commit_count' => 0,
                'qualified_commit_count' => 0,
                'repository_count' => 0,
            ];

            $rows[] = [
                'id' => (int) $session->id,
                'status' => (string) $session->status,
                'fiscal_year' => (string) ($taxIdentity['fiscal_year'] ?? ''),
                'denomination' => (string) ($taxIdentity['denomination'] ?? ''),
                'period' => [
                    'from' => $this->iso($session->period_from),
                    'to' => $this->iso($session->period_to),
                ],
                'classifier' => [
                    'provider' => (string) ($session->classifier_provider ?? ''),
                    'model' => (string) ($session->classifier_model ?? ''),
                    'seed' => (int) ($session->classifier_seed ?? 0),
                ],
                'cost' => [
                    'projected_eur' => $session->cost_eur_projected !== null ? (float) $session->cost_eur_projected : null,
                    'actual_eur' => $session->cost_eur_actual !== null ? (float) $session->cost_eur_actual : null,
                ],
                'summary' => $summary,
                'finished_at' => $this->iso($session->finished_at),
                'created_at' => $this->iso($session->created_at),
            ];
        }

        return ApiResponse::success($rows, [
            'page' => (int) $paginator->currentPage(),
            'per_page' => (int) $paginator->perPage(),
            'total' => (int) $paginator->total(),
        ]);
    }

    /**
     * @return array{commit_count:int,qualified_commit_count:int,repository_count:int}
     */
    private function summariesFor(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        $rows = TrackedCommit::query()
            ->select([
                'tracking_session_id',
                DB::raw('COUNT(*) AS commit_count'),
                DB::raw('SUM(CASE WHEN is_rd_qualified = 1 THEN 1 ELSE 0 END) AS qualified_commit_count'),
                DB::raw('COUNT(DISTINCT repository_path) AS repository_count'),
            ])
            ->whereIn('tracking_session_id', $sessionIds)
            ->groupBy('tracking_session_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $sessionId = (int) $row->tracking_session_id;
            $out[$sessionId] = [
                'commit_count' => (int) $row->commit_count,
                'qualified_commit_count' => (int) $row->qualified_commit_count,
                'repository_count' => (int) $row->repository_count,
            ];
        }

        return $out;
    }

    private function iso(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }

        return null;
    }
}
