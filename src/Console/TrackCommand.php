<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Padosoft\PatentBoxTracker\Classifier\ClassifierBatcher;
use Padosoft\PatentBoxTracker\Classifier\CommitClassification;
use Padosoft\PatentBoxTracker\Classifier\CostCapExceededException;
use Padosoft\PatentBoxTracker\Classifier\CostCapGuard;
use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\PersistsEvidenceTrait;
use Padosoft\PatentBoxTracker\Sources\CollectorContext;
use Padosoft\PatentBoxTracker\Sources\CollectorRegistry;
use Padosoft\PatentBoxTracker\Sources\EvidenceItem;
use Padosoft\PatentBoxTracker\Sources\Internal\GitProcess;
use Throwable;

/**
 * `php artisan patent-box:track <repo>` — single-repository tracking
 * pipeline.
 *
 * Walks a single git repository, dispatches the registered evidence
 * collectors, classifies every commit through the LLM batcher, and
 * persists the result into the audit-trail tables (`tracking_sessions`
 * + `tracked_commits` + `tracked_evidence`).
 *
 * The sister command {@see CrossRepoCommand} orchestrates the same
 * logic across N repos from a YAML config; this command is the
 * single-repo entrypoint suitable for ad-hoc runs.
 *
 * Exit codes:
 *   - 0 — success (or successful dry-run projection)
 *   - 1 — validation error (missing identity, bad date, unsupported role)
 *   - 2 — cost-cap exceeded
 *   - 3 — repository walk failure (non-git path, missing .git, empty period)
 */
final class TrackCommand extends Command
{
    use PersistsEvidenceTrait;

    /** @var string */
    protected $signature = 'patent-box:track
        {repo : Path to the git repository to track.}
        {--from= : ISO-8601 date (YYYY-MM-DD) — start of the reporting period.}
        {--to= : ISO-8601 date (YYYY-MM-DD) — end of the reporting period.}
        {--role=primary_ip : primary_ip | support | meta_self.}
        {--driver= : laravel/ai SDK driver override (defaults to config).}
        {--model= : LLM model override (defaults to config).}
        {--session= : Existing tracking_session id; when omitted, creates a new session.}
        {--denomination= : Tax-identity denomination (required when creating a new session).}
        {--p-iva= : Tax-identity P.IVA (required when creating a new session).}
        {--fiscal-year= : Tax-identity fiscal year (defaults to from-date year).}
        {--regime=documentazione_idonea : documentazione_idonea | non_documentazione.}
        {--cost-cap= : Override cost_cap_eur_per_run (defaults to config).}
        {--dry-run : Project token cost only; do not classify.}';

    /** @var string */
    protected $description = 'Walk a git repository, classify every commit, and persist the dossier audit trail.';

    public const ALLOWED_ROLES = ['primary_ip', 'support', 'meta_self'];

    public const ALLOWED_REGIMES = ['documentazione_idonea', 'non_documentazione'];

    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly ClassifierBatcher $batcher,
        private readonly CostCapGuard $costCapGuard,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $repoPath = (string) $this->argument('repo');
        if (trim($repoPath) === '') {
            $this->error('Repository path is required.');

            return 1;
        }

        if (! is_dir($repoPath) || ! GitProcess::isRepository($repoPath)) {
            $this->error(sprintf('Repository path "%s" does not exist or is not a git repository.', $repoPath));

            return 3;
        }

        $role = (string) $this->option('role');
        if (! in_array($role, self::ALLOWED_ROLES, true)) {
            $this->error(sprintf(
                'Unsupported --role "%s". Allowed: %s.',
                $role,
                implode(', ', self::ALLOWED_ROLES),
            ));

            return 1;
        }

        try {
            $periodFrom = $this->parsePeriodOption('from', $this->option('from'));
            $periodTo = $this->parsePeriodOption('to', $this->option('to'));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        if ($periodFrom >= $periodTo) {
            $this->error('Option --from must be strictly earlier than --to.');

            return 1;
        }

        try {
            $session = $this->resolveOrCreateSession($periodFrom, $periodTo);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return 1;
        } catch (ModelNotFoundException $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        $context = new CollectorContext(
            repositoryPath: $repoPath,
            repositoryRole: $role,
            branch: null,
            periodFrom: $periodFrom,
            periodTo: $periodTo,
            excludedAuthors: $this->resolveExcludedAuthors(),
        );

        $items = [];
        try {
            foreach ($this->registry->dispatch($context) as $item) {
                $items[] = $item;
            }
        } catch (Throwable $exception) {
            $this->error(sprintf(
                'Repository walk failed for "%s": %s',
                $repoPath,
                $exception->getMessage(),
            ));

            return 3;
        }

        $this->persistEvidence($session, $items);

        $commits = array_values(array_filter(
            $items,
            static fn (EvidenceItem $i): bool => $i->kind === EvidenceItem::KIND_COMMIT,
        ));
        $commitCount = count($commits);

        if ($commitCount === 0) {
            $this->warn(sprintf(
                'No qualifying commits found between %s and %s in "%s".',
                $periodFrom->format('Y-m-d'),
                $periodTo->format('Y-m-d'),
                $repoPath,
            ));

            return 3;
        }

        $modelName = $this->resolveModelName();
        $driverName = $this->resolveDriverName();
        $costCap = $this->resolveCostCap();

        $projection = $this->costCapGuard->project($commitCount, $modelName);
        $session->cost_eur_projected = $projection;
        $session->classifier_provider = $driverName;
        $session->classifier_model = $modelName;
        $session->save();

        if ($this->option('dry-run')) {
            $this->info($this->formatDryRunReport(
                $repoPath,
                $commitCount,
                $modelName,
                $projection,
                $costCap,
            ));

            return 0;
        }

        try {
            $this->costCapGuard->abortIfExceeded($commitCount, $modelName, $costCap);
        } catch (CostCapExceededException $exception) {
            $session->status = TrackingSession::STATUS_FAILED;
            $session->save();
            $this->error($exception->getMessage());

            return 2;
        }

        $session->status = TrackingSession::STATUS_RUNNING;
        $session->save();

        $persistedClassifications = $this->classifyAndPersist($session, $items);

        $session->status = TrackingSession::STATUS_CLASSIFIED;
        $session->cost_eur_actual = $projection;
        $session->finished_at = now()->toDateTimeString();
        $session->save();

        $this->printSummary($session, $persistedClassifications);

        return 0;
    }

    private function parsePeriodOption(string $name, mixed $raw): DateTimeImmutable
    {
        if ($raw === null || trim((string) $raw) === '') {
            throw new \InvalidArgumentException(sprintf('Option --%s is required (use YYYY-MM-DD).', $name));
        }

        $value = (string) $raw;
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException(sprintf(
                'Option --%s must match YYYY-MM-DD, got "%s".',
                $name,
                $value,
            ));
        }

        try {
            return new DateTimeImmutable($value.'T00:00:00Z');
        } catch (\Exception $exception) {
            throw new \InvalidArgumentException(sprintf(
                'Option --%s "%s" is not a valid date: %s',
                $name,
                $value,
                $exception->getMessage(),
            ));
        }
    }

    private function resolveOrCreateSession(DateTimeImmutable $periodFrom, DateTimeImmutable $periodTo): TrackingSession
    {
        $sessionOption = $this->option('session');
        if ($sessionOption !== null && trim((string) $sessionOption) !== '') {
            $id = (int) $sessionOption;
            if ($id <= 0) {
                throw new \InvalidArgumentException(sprintf(
                    'Option --session "%s" must be a positive integer.',
                    (string) $sessionOption,
                ));
            }

            /** @var TrackingSession $session */
            $session = TrackingSession::query()->findOrFail($id);

            return $session;
        }

        $denomination = $this->stringOption('denomination');
        $pIva = $this->stringOption('p-iva');
        if ($denomination === null || $pIva === null) {
            throw new \InvalidArgumentException(
                'Creating a new session requires both --denomination and --p-iva.'
            );
        }

        $regime = (string) $this->option('regime');
        if (! in_array($regime, self::ALLOWED_REGIMES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported --regime "%s". Allowed: %s.',
                $regime,
                implode(', ', self::ALLOWED_REGIMES),
            ));
        }

        $fiscalYear = $this->stringOption('fiscal-year') ?? $periodFrom->format('Y');

        $session = new TrackingSession;
        $session->tax_identity_json = [
            'denomination' => $denomination,
            'p_iva' => $pIva,
            'fiscal_year' => $fiscalYear,
            'regime' => $regime,
        ];
        $session->cost_model_json = [];
        $session->period_from = $periodFrom->format('Y-m-d H:i:s');
        $session->period_to = $periodTo->format('Y-m-d H:i:s');
        $session->status = TrackingSession::STATUS_PENDING;
        $session->classifier_seed = $this->resolveSeed();
        $session->save();

        return $session;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<string>
     */
    private function resolveExcludedAuthors(): array
    {
        $config = (array) config('patent-box-tracker.excluded_authors', []);

        return array_values(array_filter($config, 'is_string'));
    }

    private function resolveModelName(): string
    {
        $override = $this->stringOption('model');
        if ($override !== null) {
            return $override;
        }

        return (string) config('patent-box-tracker.classifier.model', 'claude-sonnet-4-6');
    }

    private function resolveDriverName(): string
    {
        $override = $this->stringOption('driver');
        if ($override !== null) {
            return $override;
        }

        return (string) config('patent-box-tracker.classifier.driver', 'regolo');
    }

    private function resolveCostCap(): float
    {
        $override = $this->option('cost-cap');
        if ($override !== null && trim((string) $override) !== '') {
            $value = (float) $override;
            if ($value > 0) {
                return $value;
            }
        }

        return (float) config('patent-box-tracker.classifier.cost_cap_eur_per_run', 50.0);
    }

    private function resolveSeed(): int
    {
        $value = config('patent-box-tracker.classifier.seed');

        return is_int($value) ? $value : 0;
    }

    /**
     * @param  list<EvidenceItem>  $items
     * @return list<CommitClassification>
     */
    private function classifyAndPersist(TrackingSession $session, array $items): array
    {
        $bySha = [];
        foreach ($items as $item) {
            if ($item->kind === EvidenceItem::KIND_COMMIT && $item->sha !== null) {
                $bySha[$item->sha] = $item;
            }
        }

        $persisted = [];
        foreach ($this->batcher->classifyAll($items) as $classification) {
            if (! isset($bySha[$classification->sha])) {
                continue;
            }
            $commitItem = $bySha[$classification->sha];

            $row = $this->buildCommitRow($commitItem, $classification);
            TrackedCommit::query()->updateOrCreate(
                [
                    'tracking_session_id' => $session->id,
                    'repository_path' => $commitItem->repositoryPath,
                    'sha' => $classification->sha,
                ],
                $row,
            );

            $persisted[] = $classification;
        }

        return $persisted;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCommitRow(
        EvidenceItem $commitItem,
        CommitClassification $classification,
    ): array {
        $payload = $commitItem->payload;

        return [
            'repository_role' => (string) $this->option('role'),
            'author_name' => $this->payloadString($payload, 'authorName'),
            'author_email' => $this->payloadString($payload, 'authorEmail'),
            'committer_email' => $this->payloadString($payload, 'committerEmail'),
            'committed_at' => $this->payloadString($payload, 'committedAt'),
            'message' => $this->payloadString($payload, 'message'),
            'files_changed_count' => is_array($payload['filesChanged'] ?? null)
                ? count($payload['filesChanged'])
                : 0,
            'insertions' => is_int($payload['insertions'] ?? null) ? $payload['insertions'] : 0,
            'deletions' => is_int($payload['deletions'] ?? null) ? $payload['deletions'] : 0,
            'phase' => $classification->phase->value,
            'is_rd_qualified' => $classification->isRdQualified,
            'rd_qualification_confidence' => $classification->rdQualificationConfidence,
            'rationale' => $classification->rationale,
            'rejected_phase' => $classification->rejectedPhase?->value,
            'evidence_used_json' => $classification->evidenceUsed,
            'hash_chain_prev' => $this->payloadString($payload, 'hashChainPrev'),
            'hash_chain_self' => $this->payloadString($payload, 'hashChainSelf'),
        ];
    }

    /**
     * @param  list<CommitClassification>  $persisted
     */
    private function printSummary(TrackingSession $session, array $persisted): void
    {
        $byPhase = [];
        foreach ($persisted as $classification) {
            $key = $classification->phase->value;
            $byPhase[$key] = ($byPhase[$key] ?? 0) + 1;
        }
        ksort($byPhase);

        $this->info(sprintf(
            'Track session #%d classified %d commit(s):',
            $session->id ?? 0,
            count($persisted),
        ));
        foreach ($byPhase as $phase => $count) {
            $this->line(sprintf('  - %-15s %d', $phase, $count));
        }

        /** @var TrackedCommit|null $lastChainRow */
        $lastChainRow = TrackedCommit::query()
            ->where('tracking_session_id', $session->id)
            ->orderByDesc('committed_at')
            ->orderByDesc('id')
            ->first();
        if ($lastChainRow !== null) {
            $this->line(sprintf('  hash-chain head: %s', (string) $lastChainRow->hash_chain_self));
        }
    }

    private function formatDryRunReport(
        string $repoPath,
        int $commitCount,
        string $model,
        ?float $projection,
        float $costCap,
    ): string {
        $lines = [
            sprintf('Dry-run projection for "%s":', $repoPath),
            sprintf('  Commits in window: %d', $commitCount),
            sprintf('  Classifier model:  %s', $model),
        ];
        if ($projection === null) {
            $lines[] = '  Projected cost:    unknown (model not in price map)';
        } else {
            $lines[] = sprintf('  Projected cost:    EUR %.4f', $projection);
        }
        $lines[] = sprintf('  Cost cap:          EUR %.2f', $costCap);
        $lines[] = '  No classifier calls were made.';

        return implode("\n", $lines);
    }
}
