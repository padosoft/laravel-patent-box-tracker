<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Renderers;

use DateTimeImmutable;
use DateTimeZone;
use Padosoft\PatentBoxTracker\Hash\HashChainBuilder;
use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackedEvidence;
use Padosoft\PatentBoxTracker\Models\TrackingSession;

/**
 * Walks a {@see TrackingSession} and assembles the canonical data
 * payload consumed by both the JSON renderer (sidecar) and the PDF
 * renderer (Italian Blade template).
 *
 * Responsibilities:
 *   - Reads the session's tracked_commits + tracked_evidence rows.
 *   - Aggregates the phase + AI-attribution breakdowns.
 *   - Computes the per-commit hourly cost projection from the
 *     session's cost_model_json (fallback hourly_rate_eur=0 if
 *     unset) and the qualified-commit count.
 *   - Re-computes the tamper-evident hash chain over the ordered
 *     commit SHAs (sorted by committed_at ascending, falling back
 *     to id) and emits both the manifest and the head digest.
 *
 * The assembler is locale-agnostic: it produces canonical English
 * machine identifiers (`research`, `implementation`, …) — the
 * renderer maps them to localised strings at render time.
 */
final class DossierPayloadAssembler
{
    public const DOSSIER_VERSION = '0.1';

    /**
     * Phase keys always present in the breakdown — even when zero —
     * so downstream consumers (commercialisti, gestionali) can rely
     * on the schema shape.
     *
     * @var list<string>
     */
    private const PHASE_KEYS = [
        'research',
        'design',
        'implementation',
        'validation',
        'documentation',
        'non_qualified',
    ];

    /**
     * AI-attribution buckets always present in the breakdown — even
     * when zero — so the renderer template can index them safely.
     *
     * @var list<string>
     */
    private const AI_ATTRIBUTION_KEYS = ['human', 'ai_assisted', 'ai_authored', 'mixed'];

    public function __construct(private readonly HashChainBuilder $hashChainBuilder) {}

    /**
     * Build the canonical dossier payload for the given session.
     *
     * @return array{
     *   dossier_version: string,
     *   generated_at: string,
     *   tax_identity: array<string, mixed>,
     *   reporting_period: array{from: string|null, to: string|null},
     *   summary: array{
     *     total_qualified_hours_estimate: float,
     *     total_qualified_cost_eur: float,
     *     phase_breakdown: array<string, int>,
     *     ai_attribution: array<string, float>,
     *   },
     *   repositories: list<array<string, mixed>>,
     *   ip_outputs: list<array<string, mixed>>,
     *   commits: list<array<string, mixed>>,
     *   evidence_links: list<array<string, mixed>>,
     *   hash_chain: array{head: string, manifest: list<array{sha: string, prev: string|null, self: string}>},
     * }
     */
    public function assemble(TrackingSession $session): array
    {
        $commits = $this->loadCommits($session);
        $evidence = $this->loadEvidence($session);

        $manifest = $this->hashChainBuilder->chain(array_map(
            static fn (TrackedCommit $c): string => (string) $c->sha,
            $commits,
        ));

        $phaseBreakdown = $this->computePhaseBreakdown($commits);
        $aiAttribution = $this->computeAiAttribution($commits);

        $costModel = (array) ($session->cost_model_json ?? []);
        $hourlyRateEur = (float) ($costModel['hourly_rate_eur'] ?? 0);
        $qualifiedHours = $this->computeQualifiedHoursEstimate($commits);
        $qualifiedCostEur = $hourlyRateEur * $qualifiedHours;

        $taxIdentity = (array) ($session->tax_identity_json ?? []);

        return [
            'dossier_version' => self::DOSSIER_VERSION,
            'generated_at' => $this->nowIsoUtc(),
            'tax_identity' => $taxIdentity,
            'reporting_period' => [
                'from' => $this->formatIso8601($session->period_from),
                'to' => $this->formatIso8601($session->period_to),
            ],
            'summary' => [
                'total_qualified_hours_estimate' => $qualifiedHours,
                'total_qualified_cost_eur' => $qualifiedCostEur,
                'phase_breakdown' => $phaseBreakdown,
                'ai_attribution' => $aiAttribution,
            ],
            'repositories' => $this->buildRepositoriesSection($commits),
            'ip_outputs' => $this->buildIpOutputsSection($session),
            'commits' => array_map(
                fn (TrackedCommit $c, int $i): array => $this->buildCommitRow($c, $manifest[$i] ?? null),
                $commits,
                array_keys($commits),
            ),
            'evidence_links' => array_map(
                fn (TrackedEvidence $e): array => $this->buildEvidenceRow($e),
                $evidence,
            ),
            'hash_chain' => [
                'head' => $manifest === []
                    ? hash('sha256', '')
                    : (string) end($manifest)['self'],
                'manifest' => $manifest,
            ],
        ];
    }

    /**
     * @return list<TrackedCommit>
     */
    private function loadCommits(TrackingSession $session): array
    {
        /** @var list<TrackedCommit> $commits */
        $commits = TrackedCommit::query()
            ->where('tracking_session_id', $session->id)
            ->orderBy('committed_at')
            ->orderBy('id')
            ->get()
            ->all();

        return $commits;
    }

    /**
     * @return list<TrackedEvidence>
     */
    private function loadEvidence(TrackingSession $session): array
    {
        /** @var list<TrackedEvidence> $evidence */
        $evidence = TrackedEvidence::query()
            ->where('tracking_session_id', $session->id)
            ->orderBy('first_seen_at')
            ->orderBy('id')
            ->get()
            ->all();

        return $evidence;
    }

    /**
     * @param  list<TrackedCommit>  $commits
     * @return array<string, int>
     */
    private function computePhaseBreakdown(array $commits): array
    {
        $breakdown = array_fill_keys(self::PHASE_KEYS, 0);
        foreach ($commits as $commit) {
            $phase = (string) ($commit->phase ?? 'non_qualified');
            if (! array_key_exists($phase, $breakdown)) {
                $phase = 'non_qualified';
            }
            $breakdown[$phase]++;
        }

        return $breakdown;
    }

    /**
     * @param  list<TrackedCommit>  $commits
     * @return array<string, float>
     */
    private function computeAiAttribution(array $commits): array
    {
        $counts = array_fill_keys(self::AI_ATTRIBUTION_KEYS, 0);
        $total = 0;
        foreach ($commits as $commit) {
            $bucket = (string) ($commit->ai_attribution ?? 'human');
            if (! array_key_exists($bucket, $counts)) {
                $bucket = 'human';
            }
            $counts[$bucket]++;
            $total++;
        }

        $result = array_fill_keys(self::AI_ATTRIBUTION_KEYS, 0.0);
        if ($total === 0) {
            return $result;
        }

        foreach ($counts as $bucket => $count) {
            $result[$bucket] = round($count / $total, 4);
        }

        return $result;
    }

    /**
     * Hours estimate per qualified commit. v0.1 uses the simple
     * `1.0 hour per qualified commit` proxy — see PLAN-W4 §4 for
     * the full cost model. The real time-allocation logic
     * (BranchSemanticsCollector + commit cadence) lands in W4.D.
     *
     * @param  list<TrackedCommit>  $commits
     */
    private function computeQualifiedHoursEstimate(array $commits): float
    {
        $hours = 0.0;
        foreach ($commits as $commit) {
            if ((bool) ($commit->is_rd_qualified ?? false) === true) {
                // TODO(W4.D): replace the flat 1.0h-per-commit proxy with the
                // BranchSemanticsCollector + commit-cadence-derived allocation.
                $hours += 1.0;
            }
        }

        return $hours;
    }

    /**
     * @param  list<TrackedCommit>  $commits
     * @return list<array<string, mixed>>
     */
    private function buildRepositoriesSection(array $commits): array
    {
        $byPath = [];
        foreach ($commits as $commit) {
            $path = (string) ($commit->repository_path ?? '');
            if ($path === '') {
                continue;
            }

            if (! isset($byPath[$path])) {
                $byPath[$path] = [
                    'path' => $path,
                    'role' => (string) ($commit->repository_role ?? 'support'),
                    'commit_count' => 0,
                    'qualified_commit_count' => 0,
                    'authors' => [],
                ];
            }

            $byPath[$path]['commit_count']++;
            if ((bool) ($commit->is_rd_qualified ?? false) === true) {
                $byPath[$path]['qualified_commit_count']++;
            }
            $email = (string) ($commit->author_email ?? '');
            if ($email !== '' && ! in_array($email, $byPath[$path]['authors'], true)) {
                $byPath[$path]['authors'][] = $email;
            }
        }

        ksort($byPath);

        return array_map(
            static function (array $row): array {
                $row['author_count'] = count($row['authors']);

                return $row;
            },
            array_values($byPath),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildIpOutputsSection(TrackingSession $session): array
    {
        $taxIdentity = (array) ($session->tax_identity_json ?? []);
        $outputs = $taxIdentity['ip_outputs'] ?? [];
        if (! is_array($outputs)) {
            return [];
        }

        $rows = [];
        foreach ($outputs as $output) {
            if (is_array($output)) {
                $rows[] = $output;
            }
        }

        return $rows;
    }

    /**
     * @param  array{sha: string, prev: string|null, self: string}|null  $chainRow
     * @return array<string, mixed>
     */
    private function buildCommitRow(TrackedCommit $commit, ?array $chainRow): array
    {
        return [
            'sha' => (string) ($commit->sha ?? ''),
            'repository_path' => (string) ($commit->repository_path ?? ''),
            'repository_role' => $commit->repository_role,
            'author_name' => $commit->author_name,
            'author_email' => $commit->author_email,
            'committed_at' => $this->formatIso8601($commit->committed_at),
            'message_subject' => $this->extractMessageSubject((string) ($commit->message ?? '')),
            'phase' => $commit->phase,
            'is_rd_qualified' => (bool) ($commit->is_rd_qualified ?? false),
            'rd_qualification_confidence' => $commit->rd_qualification_confidence !== null
                ? (float) $commit->rd_qualification_confidence
                : null,
            'rationale' => $commit->rationale,
            'rejected_phase' => $commit->rejected_phase,
            'evidence_used' => (array) ($commit->evidence_used_json ?? []),
            'ai_attribution' => $commit->ai_attribution,
            'branch_name_canonical' => $commit->branch_name_canonical,
            'prev_hash' => $chainRow['prev'] ?? null,
            'self_hash' => $chainRow['self'] ?? null,
        ];
    }

    private function extractMessageSubject(string $message): string
    {
        $first = strtok($message, "\n");

        return $first === false ? '' : trim($first);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEvidenceRow(TrackedEvidence $evidence): array
    {
        return [
            'kind' => (string) ($evidence->kind ?? ''),
            'path' => $evidence->path,
            'slug' => $evidence->slug,
            'title' => $evidence->title,
            'first_seen_at' => $this->formatIso8601($evidence->first_seen_at),
            'last_modified_at' => $this->formatIso8601($evidence->last_modified_at),
            'linked_commit_count' => (int) ($evidence->linked_commit_count ?? 0),
        ];
    }

    private function nowIsoUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Format an arbitrary datetime-shaped value into the Iso8601
     * Zulu form used in the dossier JSON. Accepts Carbon /
     * DateTimeInterface / string / null — Eloquent's `datetime`
     * casts return Carbon while raw assignments may pass strings.
     */
    private function formatIso8601(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s\Z');
        }

        if (is_string($value) && $value !== '') {
            try {
                return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                    ->format('Y-m-d\TH:i:s\Z');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
