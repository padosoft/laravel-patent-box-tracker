<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Padosoft\PatentBoxTracker\Api\ApiResponse;
use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackedDossier;
use Padosoft\PatentBoxTracker\Models\TrackingSession;

final class ShowTrackingSessionController extends Controller
{
    public function __invoke(int $trackingSession): JsonResponse
    {
        $session = TrackingSession::query()->find((int) $trackingSession);
        if (! $session instanceof TrackingSession) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', [], 404);
        }

        $taxIdentity = (array) ($session->tax_identity_json ?? []);

        $repositories = TrackedCommit::query()
            ->where('tracking_session_id', $session->id)
            ->selectRaw('repository_path, repository_role, COUNT(*) as commit_count')
            ->groupBy('repository_path', 'repository_role')
            ->orderBy('repository_path')
            ->get()
            ->map(static fn ($row): array => [
                'path' => (string) $row->repository_path,
                'role' => (string) ($row->repository_role ?? ''),
                'commit_count' => (int) $row->commit_count,
            ])
            ->all();

        $dossierRows = TrackedDossier::query()
            ->where('tracking_session_id', $session->id)
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->get();
        $dossiers = [];
        foreach ($dossierRows as $dossier) {
            if (! $dossier instanceof TrackedDossier) {
                continue;
            }

            $dossiers[] = [
                'id' => (int) $dossier->id,
                'format' => (string) $dossier->format,
                'locale' => (string) $dossier->locale,
                'byte_size' => $dossier->byte_size !== null ? (int) $dossier->byte_size : null,
                'sha256' => $dossier->sha256,
                'generated_at' => $this->iso($dossier->generated_at),
            ];
        }

        $head = TrackedCommit::query()
            ->where('tracking_session_id', $session->id)
            ->orderByDesc('committed_at')
            ->orderByDesc('id')
            ->value('hash_chain_self');

        return ApiResponse::success([
            'id' => (int) $session->id,
            'status' => (string) $session->status,
            'tax_identity' => $taxIdentity,
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
            'repositories' => $repositories,
            'dossiers' => $dossiers,
            'hash_chain_head' => is_string($head) ? $head : null,
            'finished_at' => $this->iso($session->getAttribute('finished_at')),
            'created_at' => $this->iso($session->getAttribute('created_at')),
        ]);
    }

    private function iso(mixed $value): ?string
    {
        $tz = new \DateTimeZone('UTC');
        if ($value instanceof \DateTimeImmutable) {
            return $value->setTimezone($tz)->format('Y-m-d\TH:i:s\Z');
        }
        if ($value instanceof \DateTime) {
            return (clone $value)->setTimezone($tz)->format('Y-m-d\TH:i:s\Z');
        }

        return null;
    }
}
