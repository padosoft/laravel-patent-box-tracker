<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Console;

use Illuminate\Console\Command;
use Padosoft\PatentBoxTracker\Classifier\ClassifierBatcher;
use Padosoft\PatentBoxTracker\Classifier\CommitClassification;
use Padosoft\PatentBoxTracker\Classifier\CostCapExceededException;
use Padosoft\PatentBoxTracker\Classifier\CostCapGuard;
use Padosoft\PatentBoxTracker\Config\CrossRepoConfig;
use Padosoft\PatentBoxTracker\Config\CrossRepoConfigException;
use Padosoft\PatentBoxTracker\Config\CrossRepoConfigValidator;
use Padosoft\PatentBoxTracker\Config\RepoConfig;
use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackedEvidence;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Sources\CollectorContext;
use Padosoft\PatentBoxTracker\Sources\CollectorRegistry;
use Padosoft\PatentBoxTracker\Sources\EvidenceItem;
use Throwable;

/**
 * `php artisan patent-box:cross-repo <yml>` — multi-repository
 * orchestrator for the canonical Padosoft Patent Box dossier.
 *
 * One YAML config produces ONE tracking session covering N
 * repositories with per-repo roles. Each repository is walked
 * independently and its commits stream into a SHARED audit-trail
 * (`tracked_commits` keyed by `(session, repo_path, sha)`); the
 * dossier renderer aggregates the results into per-repo summaries
 * and a cross-repo executive summary.
 *
 * Streaming behaviour: progress is emitted to stderr as each repo
 * completes (`[3/6] askmydocs: 247 commits classified ...`) so the
 * operator can interrupt safely once they've seen the cost.
 *
 * Exit codes:
 *   - 0 — success (or successful dry-run projection)
 *   - 1 — config validation error
 *   - 2 — cost-cap exceeded
 *   - 3 — repository walk failure for one or more repos
 */
final class CrossRepoCommand extends Command
{
    /** @var string */
    protected $signature = 'patent-box:cross-repo
        {config : Path to the YAML config file describing the cross-repo run.}
        {--dry-run : Project token cost across every repo; do not classify.}';

    /** @var string */
    protected $description = 'Run the Patent Box tracker across N repos from a YAML config file (one consolidated dossier).';

    public function __construct(
        private readonly CrossRepoConfigValidator $validator,
        private readonly CollectorRegistry $registry,
        private readonly ClassifierBatcher $batcher,
        private readonly CostCapGuard $costCapGuard,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $configPath = (string) $this->argument('config');
        if (trim($configPath) === '') {
            $this->error('Config file path is required.');

            return 1;
        }

        try {
            $config = $this->validator->validateFile($configPath);
        } catch (CrossRepoConfigException $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        $session = $this->createCrossRepoSession($config);

        $itemsByRepo = [];
        $commitCountByRepo = [];
        $totalCommits = 0;
        $walkFailures = [];

        $this->info(sprintf(
            'Cross-repo run for fiscal year %s — %d repositories, period %s → %s.',
            $config->fiscalYear,
            $config->repositoryCount(),
            $config->periodFrom->format('Y-m-d'),
            $config->periodTo->format('Y-m-d'),
        ));

        $excludedAuthors = $this->resolveExcludedAuthors();
        $position = 0;
        foreach ($config->repositories as $repoConfig) {
            $position++;
            $this->writeStderr(sprintf(
                "[%d/%d] %s (%s) — walking…\n",
                $position,
                $config->repositoryCount(),
                $repoConfig->path,
                $repoConfig->role,
            ));

            $context = new CollectorContext(
                repositoryPath: $repoConfig->path,
                repositoryRole: $repoConfig->role,
                branch: null,
                periodFrom: $config->periodFrom,
                periodTo: $config->periodTo,
                excludedAuthors: $excludedAuthors,
            );

            $items = [];
            try {
                foreach ($this->registry->dispatch($context) as $item) {
                    $items[] = $item;
                }
            } catch (Throwable $exception) {
                $walkFailures[] = sprintf(
                    '%s: %s',
                    $repoConfig->path,
                    $exception->getMessage(),
                );

                continue;
            }

            $commits = array_values(array_filter(
                $items,
                static fn (EvidenceItem $i): bool => $i->kind === EvidenceItem::KIND_COMMIT,
            ));

            $itemsByRepo[$repoConfig->path] = $items;
            $commitCountByRepo[$repoConfig->path] = count($commits);
            $totalCommits += count($commits);

            $this->persistEvidence($session, $items);
        }

        if ($walkFailures !== []) {
            $session->status = TrackingSession::STATUS_FAILED;
            $session->save();
            $this->error('Cross-repo run aborted; the following repositories failed to walk:');
            foreach ($walkFailures as $message) {
                $this->error('  - '.$message);
            }

            return 3;
        }

        $modelName = $config->classifier['model'];
        $driverName = $config->classifier['provider'];
        $costCap = (float) config('patent-box-tracker.classifier.cost_cap_eur_per_run', 50.0);

        $projection = $this->costCapGuard->project($totalCommits, $modelName);
        $session->classifier_provider = $driverName;
        $session->classifier_model = $modelName;
        $session->cost_eur_projected = $projection;
        $session->save();

        if ($this->option('dry-run')) {
            $this->info($this->formatDryRunReport(
                $config,
                $commitCountByRepo,
                $totalCommits,
                $modelName,
                $projection,
                $costCap,
            ));

            return 0;
        }

        try {
            $this->costCapGuard->abortIfExceeded($totalCommits, $modelName, $costCap);
        } catch (CostCapExceededException $exception) {
            $session->status = TrackingSession::STATUS_FAILED;
            $session->save();
            $this->error($exception->getMessage());

            return 2;
        }

        $session->status = TrackingSession::STATUS_RUNNING;
        $session->save();

        $persistedByRepo = [];
        foreach ($config->repositories as $idx => $repoConfig) {
            $items = $itemsByRepo[$repoConfig->path] ?? [];
            $persisted = $this->classifyAndPersist($session, $repoConfig, $items);
            $persistedByRepo[$repoConfig->path] = $persisted;

            $this->writeStderr(sprintf(
                "[%d/%d] %s: %d commit(s) classified.\n",
                $idx + 1,
                $config->repositoryCount(),
                $this->shortRepoLabel($repoConfig->path),
                count($persisted),
            ));
        }

        $session->status = TrackingSession::STATUS_CLASSIFIED;
        $session->cost_eur_actual = $projection;
        $session->finished_at = now()->toDateTimeString();
        $session->save();

        $this->printSummary($session, $persistedByRepo, $config);

        return 0;
    }

    private function createCrossRepoSession(CrossRepoConfig $config): TrackingSession
    {
        $taxIdentity = array_merge($config->taxIdentity, [
            'fiscal_year' => $config->fiscalYear,
            'ip_outputs' => $config->ipOutputs,
            'manual_supplement' => $config->manualSupplement,
        ]);

        $session = new TrackingSession;
        $session->tax_identity_json = $taxIdentity;
        $session->cost_model_json = $config->costModel;
        $session->period_from = $config->periodFrom->format('Y-m-d H:i:s');
        $session->period_to = $config->periodTo->format('Y-m-d H:i:s');
        $session->status = TrackingSession::STATUS_PENDING;
        $session->classifier_seed = $this->resolveSeed();
        $session->save();

        return $session;
    }

    private function resolveSeed(): int
    {
        $value = config('patent-box-tracker.classifier.seed');

        return is_int($value) ? $value : 0;
    }

    /**
     * @return list<string>
     */
    private function resolveExcludedAuthors(): array
    {
        $config = (array) config('patent-box-tracker.excluded_authors', []);

        return array_values(array_filter($config, 'is_string'));
    }

    /**
     * @param  list<EvidenceItem>  $items
     */
    private function persistEvidence(TrackingSession $session, array $items): void
    {
        foreach ($items as $item) {
            if ($item->kind !== EvidenceItem::KIND_DESIGN_DOC_LINK) {
                continue;
            }

            $payload = $item->payload;
            $slug = is_string($payload['slug'] ?? null) ? (string) $payload['slug'] : null;
            $kind = is_string($payload['kind'] ?? null) ? (string) $payload['kind'] : 'plan';
            $path = is_string($payload['path'] ?? null) ? (string) $payload['path'] : null;
            $title = is_string($payload['title'] ?? null) ? (string) $payload['title'] : null;

            TrackedEvidence::query()->updateOrCreate(
                [
                    'tracking_session_id' => $session->id,
                    'kind' => $kind,
                    'slug' => $slug,
                    'path' => $path,
                ],
                [
                    'title' => $title,
                    'first_seen_at' => $payload['firstSeenAt'] ?? null,
                    'last_modified_at' => $payload['lastModifiedAt'] ?? null,
                    'linked_commit_count' => 0,
                ],
            );
        }
    }

    /**
     * @param  list<EvidenceItem>  $items
     * @return list<CommitClassification>
     */
    private function classifyAndPersist(TrackingSession $session, RepoConfig $repoConfig, array $items): array
    {
        $bySha = [];
        foreach ($items as $item) {
            if ($item->kind === EvidenceItem::KIND_COMMIT && $item->sha !== null) {
                $bySha[$item->sha] = $item;
            }
        }

        // Filter the input to this repo's items only — the batcher
        // groups internally by repo, but feeding it only the matching
        // items keeps the persistence loop clean.
        $repoItems = array_values(array_filter(
            $items,
            static fn (EvidenceItem $i): bool => $i->repositoryPath === $repoConfig->path,
        ));

        $persisted = [];
        foreach ($this->batcher->classifyAll($repoItems) as $classification) {
            if (! isset($bySha[$classification->sha])) {
                continue;
            }
            $commitItem = $bySha[$classification->sha];

            TrackedCommit::query()->updateOrCreate(
                [
                    'tracking_session_id' => $session->id,
                    'repository_path' => $commitItem->repositoryPath,
                    'sha' => $classification->sha,
                ],
                [
                    'repository_role' => $repoConfig->role,
                    'author_name' => $this->payloadString($commitItem->payload, 'authorName'),
                    'author_email' => $this->payloadString($commitItem->payload, 'authorEmail'),
                    'committer_email' => $this->payloadString($commitItem->payload, 'committerEmail'),
                    'committed_at' => $this->payloadString($commitItem->payload, 'committedAt'),
                    'message' => $this->payloadString($commitItem->payload, 'message'),
                    'files_changed_count' => is_array($commitItem->payload['filesChanged'] ?? null)
                        ? count($commitItem->payload['filesChanged'])
                        : 0,
                    'insertions' => is_int($commitItem->payload['insertions'] ?? null)
                        ? $commitItem->payload['insertions']
                        : 0,
                    'deletions' => is_int($commitItem->payload['deletions'] ?? null)
                        ? $commitItem->payload['deletions']
                        : 0,
                    'phase' => $classification->phase->value,
                    'is_rd_qualified' => $classification->isRdQualified,
                    'rd_qualification_confidence' => $classification->rdQualificationConfidence,
                    'rationale' => $classification->rationale,
                    'rejected_phase' => $classification->rejectedPhase?->value,
                    'evidence_used_json' => $classification->evidenceUsed,
                    'hash_chain_prev' => $this->payloadString($commitItem->payload, 'hashChainPrev'),
                    'hash_chain_self' => $this->payloadString($commitItem->payload, 'hashChainSelf'),
                ],
            );

            $persisted[] = $classification;
        }

        return $persisted;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @param  array<string, list<CommitClassification>>  $persistedByRepo
     */
    private function printSummary(TrackingSession $session, array $persistedByRepo, CrossRepoConfig $config): void
    {
        $totalQualified = 0;
        $totalCommits = 0;
        $aggregatePhase = [];

        $this->info(sprintf('Cross-repo session #%d summary:', $session->id ?? 0));

        foreach ($config->repositories as $repoConfig) {
            $entries = $persistedByRepo[$repoConfig->path] ?? [];
            $phaseCount = [];
            $qualifiedCount = 0;
            foreach ($entries as $classification) {
                $phaseCount[$classification->phase->value] = ($phaseCount[$classification->phase->value] ?? 0) + 1;
                $aggregatePhase[$classification->phase->value] = ($aggregatePhase[$classification->phase->value] ?? 0) + 1;
                if ($classification->isRdQualified) {
                    $qualifiedCount++;
                }
            }
            $totalCommits += count($entries);
            $totalQualified += $qualifiedCount;

            ksort($phaseCount);
            $this->line(sprintf(
                '  - %s [%s]: %d commit(s), %d qualified',
                $this->shortRepoLabel($repoConfig->path),
                $repoConfig->role,
                count($entries),
                $qualifiedCount,
            ));
            foreach ($phaseCount as $phase => $count) {
                $this->line(sprintf('      %s: %d', $phase, $count));
            }
        }

        $this->line('');
        $this->line(sprintf(
            'Aggregate: %d commit(s) classified, %d qualified across %d repo(s).',
            $totalCommits,
            $totalQualified,
            $config->repositoryCount(),
        ));

        ksort($aggregatePhase);
        foreach ($aggregatePhase as $phase => $count) {
            $this->line(sprintf('  %-15s %d', $phase, $count));
        }

        /** @var TrackedCommit|null $head */
        $head = TrackedCommit::query()
            ->where('tracking_session_id', $session->id)
            ->orderByDesc('committed_at')
            ->orderByDesc('id')
            ->first();
        if ($head !== null) {
            $this->line(sprintf('  hash-chain head: %s', (string) $head->hash_chain_self));
        }
    }

    /**
     * @param  array<string, int>  $commitCountByRepo
     */
    private function formatDryRunReport(
        CrossRepoConfig $config,
        array $commitCountByRepo,
        int $totalCommits,
        string $model,
        ?float $projection,
        float $costCap,
    ): string {
        $lines = [
            sprintf(
                'Dry-run projection across %d repositories for fiscal year %s:',
                $config->repositoryCount(),
                $config->fiscalYear,
            ),
        ];
        foreach ($config->repositories as $repoConfig) {
            $count = $commitCountByRepo[$repoConfig->path] ?? 0;
            $lines[] = sprintf(
                '  - %s [%s]: %d commit(s) in window',
                $this->shortRepoLabel($repoConfig->path),
                $repoConfig->role,
                $count,
            );
        }
        $lines[] = sprintf('  Total commits:    %d', $totalCommits);
        $lines[] = sprintf('  Classifier model: %s', $model);
        if ($projection === null) {
            $lines[] = '  Projected cost:   unknown (model not in price map)';
        } else {
            $lines[] = sprintf('  Projected cost:   EUR %.4f', $projection);
        }
        $lines[] = sprintf('  Cost cap:         EUR %.2f', $costCap);
        $lines[] = '  No classifier calls were made.';

        return implode("\n", $lines);
    }

    private function shortRepoLabel(string $path): string
    {
        $parts = preg_split('#[\\\\/]#', rtrim($path, '\\/'));
        if ($parts === false || $parts === []) {
            return $path;
        }
        $tail = end($parts);

        return $tail === false || $tail === '' ? $path : $tail;
    }

    /**
     * Emit a progress line. Routed through the command's standard
     * output (rather than stderr) so test fixtures can capture it
     * via `$this->artisan(...)` without needing custom stream
     * plumbing — and so progress lines sit alongside the final
     * summary in operator transcripts.
     */
    private function writeStderr(string $message): void
    {
        // Trim the trailing newline because $this->line() adds its own.
        $this->line(rtrim($message, "\n"));
    }
}
