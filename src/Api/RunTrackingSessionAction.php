<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Api;

use DateTimeImmutable;
use Padosoft\PatentBoxTracker\Classifier\ClassifierBatcher;
use Padosoft\PatentBoxTracker\Classifier\CommitClassification;
use Padosoft\PatentBoxTracker\Classifier\CostCapGuard;
use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\PersistsEvidenceTrait;
use Padosoft\PatentBoxTracker\Sources\CollectorContext;
use Padosoft\PatentBoxTracker\Sources\CollectorRegistry;
use Padosoft\PatentBoxTracker\Sources\EvidenceItem;

final class RunTrackingSessionAction
{
    use PersistsEvidenceTrait;

    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly ClassifierBatcher $batcher,
        private readonly CostCapGuard $costCapGuard,
    ) {}

    /**
     * @param  list<array{path:string,role:string}>  $repositories
     */
    public function run(
        TrackingSession $session,
        array $repositories,
        DateTimeImmutable $periodFrom,
        DateTimeImmutable $periodTo,
        string $driver,
        string $model
    ): void {
        $allItems = [];
        $itemsByRepo = [];
        foreach ($repositories as $repo) {
            $context = new CollectorContext(
                repositoryPath: $repo['path'],
                repositoryRole: $repo['role'],
                branch: null,
                periodFrom: $periodFrom,
                periodTo: $periodTo,
                excludedAuthors: $this->resolveExcludedAuthors(),
            );

            $items = [];
            foreach ($this->registry->dispatch($context) as $item) {
                $items[] = $item;
                $allItems[] = $item;
            }
            $itemsByRepo[$repo['path']] = $items;
            $this->persistEvidence($session, $items);
        }

        $commitCount = count(array_filter(
            $allItems,
            static fn (EvidenceItem $i): bool => $i->kind === EvidenceItem::KIND_COMMIT
        ));

        $projection = $this->costCapGuard->project($commitCount, $model);
        $session->classifier_provider = $driver;
        $session->classifier_model = $model;
        $session->cost_eur_projected = $projection;
        $session->status = TrackingSession::STATUS_RUNNING;
        $session->save();

        $cap = (float) config('patent-box-tracker.classifier.cost_cap_eur_per_run', 50.0);
        $this->costCapGuard->abortIfExceeded($commitCount, $model, $cap);

        foreach ($repositories as $repo) {
            $repoItems = $itemsByRepo[$repo['path']] ?? [];
            $bySha = [];
            foreach ($repoItems as $item) {
                if ($item->kind === EvidenceItem::KIND_COMMIT && $item->sha !== null) {
                    $bySha[$item->sha] = $item;
                }
            }

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
                    $this->buildCommitRow($commitItem, $classification, $repo['role']),
                );
            }
        }

        $session->status = TrackingSession::STATUS_CLASSIFIED;
        $session->cost_eur_actual = $projection;
        $session->finished_at = now()->toDateTimeString();
        $session->save();
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
     * @return array<string,mixed>
     */
    private function buildCommitRow(EvidenceItem $commitItem, CommitClassification $classification, string $role): array
    {
        $payload = $commitItem->payload;

        return [
            'repository_role' => $role,
            'author_name' => $this->payloadString($payload, 'authorName'),
            'author_email' => $this->payloadString($payload, 'authorEmail'),
            'committer_email' => $this->payloadString($payload, 'committerEmail'),
            'committed_at' => $this->payloadString($payload, 'committedAt'),
            'message' => $this->payloadString($payload, 'message'),
            'files_changed_count' => is_array($payload['filesChanged'] ?? null) ? count($payload['filesChanged']) : 0,
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
}
