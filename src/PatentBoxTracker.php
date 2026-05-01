<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker;

use DateTimeImmutable;
use Illuminate\Support\Facades\App;
use Padosoft\PatentBoxTracker\Classifier\ClassifierBatcher;
use Padosoft\PatentBoxTracker\Classifier\CommitClassification;
use Padosoft\PatentBoxTracker\Classifier\CostCapGuard;
use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Sources\CollectorContext;
use Padosoft\PatentBoxTracker\Sources\CollectorRegistry;
use Padosoft\PatentBoxTracker\Sources\EvidenceItem;

/**
 * Public fluent builder entrypoint for the package.
 *
 * Mirrors the README quick-start verbatim:
 *
 *     $session = PatentBoxTracker::for(['/path/to/askmydocs'])
 *         ->coveringPeriod('2026-01-01', '2026-12-31')
 *         ->classifiedBy('regolo')
 *         ->withTaxIdentity([...])
 *         ->withCostModel([...])
 *         ->run();
 *
 *     $session->renderDossier()->locale('it')->toPdf()->save(...);
 *
 * The builder is intentionally non-final so consumers / tests can
 * extend it; the underlying services it delegates to (collector
 * registry, batcher, cost-cap guard) ARE final and resolved through
 * the Laravel container so they participate in normal binding swaps.
 *
 * Calling `->run()` is the only side-effecting method on the
 * builder; every other method returns `$this` for chaining and
 * mutates only the builder's local state. The dossier renderer is
 * resolved lazily off the persisted session via
 * {@see TrackingSession::renderDossier()}.
 */
class PatentBoxTracker
{
    use PersistsEvidenceTrait;

    /**
     * @var list<string>
     */
    private array $repositories;

    private ?DateTimeImmutable $periodFrom = null;

    private ?DateTimeImmutable $periodTo = null;

    private ?string $classifierDriver = null;

    private ?string $classifierModel = null;

    /**
     * @var array<string, mixed>
     */
    private array $taxIdentity = [];

    /**
     * @var array<string, mixed>
     */
    private array $costModel = [];

    private string $defaultRole = 'primary_ip';

    /**
     * @param  list<string>  $repositories
     */
    final protected function __construct(array $repositories)
    {
        $this->repositories = $repositories;
    }

    /**
     * Begin a tracking session for one or more repositories.
     *
     * Each path must be an existing git working tree; the validation
     * happens at `run()` time so a builder mis-configuration is
     * inspectable without yet touching disk.
     *
     * @param  string|list<string>  $repositories
     */
    public static function for(string|array $repositories): static
    {
        $list = is_string($repositories) ? [$repositories] : array_values($repositories);
        if ($list === []) {
            throw new \InvalidArgumentException(
                'PatentBoxTracker::for() requires at least one repository path.'
            );
        }

        foreach ($list as $idx => $path) {
            if (! is_string($path) || trim($path) === '') {
                throw new \InvalidArgumentException(sprintf(
                    'PatentBoxTracker::for() repositories[%d] must be a non-empty string path.',
                    $idx,
                ));
            }
        }

        return new static($list);
    }

    public function coveringPeriod(string $from, string $to): static
    {
        $this->periodFrom = $this->parseIsoDate('coveringPeriod from', $from);
        $this->periodTo = $this->parseIsoDate('coveringPeriod to', $to);

        if ($this->periodFrom >= $this->periodTo) {
            throw new \InvalidArgumentException(sprintf(
                'PatentBoxTracker::coveringPeriod(): from "%s" must be strictly earlier than to "%s".',
                $from,
                $to,
            ));
        }

        return $this;
    }

    public function classifiedBy(string $driver, ?string $model = null): static
    {
        if (trim($driver) === '') {
            throw new \InvalidArgumentException('PatentBoxTracker::classifiedBy() requires a non-empty driver.');
        }
        $this->classifierDriver = $driver;
        $this->classifierModel = $model;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    public function withTaxIdentity(array $identity): static
    {
        foreach (['denomination', 'p_iva'] as $required) {
            if (! array_key_exists($required, $identity) || ! is_string($identity[$required]) || trim($identity[$required]) === '') {
                throw new \InvalidArgumentException(sprintf(
                    'PatentBoxTracker::withTaxIdentity(): "%s" is required and must be a non-empty string.',
                    $required,
                ));
            }
        }

        $this->taxIdentity = $identity;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $model
     */
    public function withCostModel(array $model): static
    {
        $this->costModel = $model;

        return $this;
    }

    public function withRole(string $role): static
    {
        $allowed = ['primary_ip', 'support', 'meta_self'];
        if (! in_array($role, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf(
                'PatentBoxTracker::withRole(): role must be one of [%s], got "%s".',
                implode(', ', $allowed),
                $role,
            ));
        }
        $this->defaultRole = $role;

        return $this;
    }

    /**
     * Execute the pipeline: collect → classify → persist. Returns
     * the persisted {@see TrackingSession} so the caller can
     * render the dossier off it.
     *
     * The classifier batcher honours the cost cap from
     * `config('patent-box-tracker.classifier.cost_cap_eur_per_run')`
     * — the run aborts with a {@see CostCapExceededException} when
     * the projection exceeds the configured cap.
     */
    public function run(): TrackingSession
    {
        if ($this->periodFrom === null || $this->periodTo === null) {
            throw new \LogicException(
                'PatentBoxTracker::run(): coveringPeriod() must be called before run().'
            );
        }
        if ($this->taxIdentity === []) {
            throw new \LogicException(
                'PatentBoxTracker::run(): withTaxIdentity() must be called before run().'
            );
        }

        $session = $this->createSession();

        $registry = App::make(CollectorRegistry::class);
        $batcher = App::make(ClassifierBatcher::class);
        $costCapGuard = App::make(CostCapGuard::class);

        $allItems = [];
        foreach ($this->repositories as $repoPath) {
            $context = new CollectorContext(
                repositoryPath: $repoPath,
                repositoryRole: $this->defaultRole,
                branch: null,
                periodFrom: $this->periodFrom,
                periodTo: $this->periodTo,
                excludedAuthors: $this->resolveExcludedAuthors(),
            );

            foreach ($registry->dispatch($context) as $item) {
                $allItems[] = $item;
            }
        }

        $this->persistEvidence($session, $allItems);

        $commits = array_values(array_filter(
            $allItems,
            static fn (EvidenceItem $i): bool => $i->kind === EvidenceItem::KIND_COMMIT,
        ));
        $commitCount = count($commits);

        $modelName = $this->classifierModel ?? (string) config('patent-box-tracker.classifier.model', 'claude-sonnet-4-6');
        $driverName = $this->classifierDriver ?? (string) config('patent-box-tracker.classifier.driver', 'regolo');
        $costCap = (float) config('patent-box-tracker.classifier.cost_cap_eur_per_run', 50.0);
        $projection = $costCapGuard->project($commitCount, $modelName);

        $session->classifier_provider = $driverName;
        $session->classifier_model = $modelName;
        $session->cost_eur_projected = $projection;
        $session->save();

        $costCapGuard->abortIfExceeded($commitCount, $modelName, $costCap);

        $session->status = TrackingSession::STATUS_RUNNING;
        $session->save();

        $bySha = [];
        foreach ($allItems as $item) {
            if ($item->kind === EvidenceItem::KIND_COMMIT && $item->sha !== null) {
                $bySha[$item->sha] = $item;
            }
        }

        foreach ($batcher->classifyAll($allItems) as $classification) {
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
                $this->buildCommitRow($commitItem, $classification),
            );
        }

        $session->status = TrackingSession::STATUS_CLASSIFIED;
        $session->cost_eur_actual = $projection;
        $session->finished_at = now()->toDateTimeString();
        $session->save();

        return $session->refresh();
    }

    /**
     * @return list<string>
     */
    private function resolveExcludedAuthors(): array
    {
        $config = (array) config('patent-box-tracker.excluded_authors', []);

        return array_values(array_filter($config, 'is_string'));
    }

    private function createSession(): TrackingSession
    {
        $taxIdentity = $this->taxIdentity;
        if (! array_key_exists('fiscal_year', $taxIdentity)) {
            $taxIdentity['fiscal_year'] = $this->periodFrom?->format('Y') ?? '';
        }
        if (! array_key_exists('regime', $taxIdentity)) {
            $taxIdentity['regime'] = (string) config('patent-box-tracker.regime', 'documentazione_idonea');
        }

        $session = new TrackingSession;
        $session->tax_identity_json = $taxIdentity;
        $session->cost_model_json = $this->costModel;
        $session->period_from = $this->periodFrom?->format('Y-m-d H:i:s');
        $session->period_to = $this->periodTo?->format('Y-m-d H:i:s');
        $session->status = TrackingSession::STATUS_PENDING;

        $seed = config('patent-box-tracker.classifier.seed');
        $session->classifier_seed = is_int($seed) ? $seed : 0;

        $session->save();

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCommitRow(EvidenceItem $commitItem, CommitClassification $classification): array
    {
        $payload = $commitItem->payload;

        return [
            'repository_role' => $this->defaultRole,
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

    private function parseIsoDate(string $field, string $value): DateTimeImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException(sprintf(
                'PatentBoxTracker::%s must match YYYY-MM-DD, got "%s".',
                $field,
                $value,
            ));
        }

        try {
            return new DateTimeImmutable($value.'T00:00:00Z');
        } catch (\Exception $exception) {
            throw new \InvalidArgumentException(sprintf(
                'PatentBoxTracker::%s "%s" is not a valid date: %s',
                $field,
                $value,
                $exception->getMessage(),
            ));
        }
    }
}
