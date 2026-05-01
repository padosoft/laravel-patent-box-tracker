<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Classifier;

use Generator;
use Padosoft\PatentBoxTracker\Sources\EvidenceItem;

/**
 * Walks an iterable evidence stream and produces classifications
 * in batches.
 *
 * Batching is the cost-control mechanism: classifying 35 commits
 * at batch_size=20 fires 2 LLM calls (1× 20 + 1× 15), not 35.
 * Per-call overhead amortises cleanly because the system prompt
 * (~1500 tokens) is shared across the batch — a 100-commit run
 * is roughly 6× cheaper than 100 single-commit runs.
 *
 * Memory profile is `O(batch_size)`: only the current batch is
 * held in memory; finished classifications are yielded to the
 * caller immediately (R3 — memory-safe bulk operations).
 */
final class ClassifierBatcher
{
    public function __construct(
        private readonly CommitClassifier $classifier,
        private readonly int $batchSize,
    ) {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException(sprintf(
                'ClassifierBatcher: batchSize must be >= 1, got %d.',
                $batchSize,
            ));
        }
    }

    /**
     * Classify every commit in the evidence stream.
     *
     * Filters the input to `kind === EvidenceItem::KIND_COMMIT`
     * and ignores non-commit kinds (the design-doc / branch
     * semantics / AI attribution items are projected onto each
     * commit as `evidenceLinks` per repository).
     *
     * Yields classifications as each batch resolves so callers
     * can persist incrementally (TrackingSession status updates,
     * progress bars, partial-failure recovery).
     *
     * @param  iterable<int, EvidenceItem>  $evidenceItems
     * @return Generator<int, CommitClassification>
     */
    public function classifyAll(iterable $evidenceItems): Generator
    {
        $byRepo = $this->groupCommitsByRepo($evidenceItems);

        foreach ($byRepo as $repoBundle) {
            yield from $this->classifyRepo($repoBundle);
        }
    }

    /**
     * @param  iterable<int, EvidenceItem>  $evidenceItems
     * @return array<string, array{
     *     commits: list<EvidenceItem>,
     *     evidenceLinks: array<string, list<string>>,
     * }>
     */
    private function groupCommitsByRepo(iterable $evidenceItems): array
    {
        $byRepo = [];

        foreach ($evidenceItems as $item) {
            $repo = $item->repositoryPath;
            if (! isset($byRepo[$repo])) {
                $byRepo[$repo] = [
                    'commits' => [],
                    'evidenceLinks' => [],
                ];
            }

            switch ($item->kind) {
                case EvidenceItem::KIND_COMMIT:
                    $byRepo[$repo]['commits'][] = $item;
                    break;

                case EvidenceItem::KIND_AI_ATTRIBUTION:
                    if ($item->sha !== null) {
                        $tag = 'ai-attribution:'.$this->payloadString($item, 'attribution', 'human');
                        $byRepo[$repo]['evidenceLinks'][$item->sha][] = $tag;
                    }
                    break;

                case EvidenceItem::KIND_BRANCH_SEMANTIC:
                    $branch = $this->payloadString($item, 'branch', '');
                    if ($branch !== '') {
                        $tag = 'branch:'.$branch;
                        // Branch semantic items don't carry a SHA — the
                        // tag is repo-wide and the per-commit prompt
                        // builder picks them up via the bucket below.
                        $byRepo[$repo]['evidenceLinks'][self::repoLevelBucket()][] = $tag;
                    }
                    break;

                case EvidenceItem::KIND_DESIGN_DOC_LINK:
                    $slug = $this->payloadString($item, 'slug', '');
                    $kind = $this->payloadString($item, 'kind', 'plan');
                    if ($slug !== '') {
                        $tag = $kind.':'.$slug;
                        $byRepo[$repo]['evidenceLinks'][self::repoLevelBucket()][] = $tag;
                    }
                    break;
            }
        }

        return $byRepo;
    }

    /**
     * @param  array{commits: list<EvidenceItem>, evidenceLinks: array<string, list<string>>}  $repoBundle
     * @return Generator<int, CommitClassification>
     */
    private function classifyRepo(array $repoBundle): Generator
    {
        $commits = $repoBundle['commits'];
        if ($commits === []) {
            return;
        }

        $repoLevelLinks = $repoBundle['evidenceLinks'][self::repoLevelBucket()] ?? [];

        foreach (array_chunk($commits, $this->batchSize) as $batch) {
            [$prompts, $links] = $this->renderBatch($batch, $repoBundle['evidenceLinks'], $repoLevelLinks);

            $batchResult = $this->classifier->classify($prompts, $links);

            // Yield in input order — preserve the SHA stream the
            // collector emitted so the dossier renderer can match
            // it 1:1 with the tamper-evidence chain.
            foreach ($batch as $item) {
                $sha = (string) $item->sha;
                if (isset($batchResult[$sha])) {
                    yield $batchResult[$sha];
                }
            }
        }
    }

    /**
     * @param  list<EvidenceItem>  $batch
     * @param  array<string, list<string>>  $allLinks
     * @param  list<string>  $repoLevelLinks
     * @return array{0: list<array<string, mixed>>, 1: array<string, list<string>>}
     */
    private function renderBatch(array $batch, array $allLinks, array $repoLevelLinks): array
    {
        $prompts = [];
        $perCommitLinks = [];

        foreach ($batch as $item) {
            $sha = (string) $item->sha;
            $payload = $item->payload;

            $perCommitLinks[$sha] = array_values(array_unique(array_merge(
                $allLinks[$sha] ?? [],
                $repoLevelLinks,
            )));

            $files = [];
            $filesChanged = $payload['filesChanged'] ?? [];
            if (is_array($filesChanged)) {
                foreach ($filesChanged as $file) {
                    if (is_array($file) && isset($file['path']) && is_string($file['path'])) {
                        $files[] = $file['path'];
                    }
                }
            }

            $prompts[] = [
                'sha' => $sha,
                'subject' => (string) ($payload['subject'] ?? ''),
                'body' => (string) ($payload['body'] ?? ''),
                'author_email' => (string) ($payload['authorEmail'] ?? ''),
                'committed_at' => (string) ($payload['committedAt'] ?? ''),
                'branch' => (string) ($payload['branch'] ?? ''),
                'branch_semantics' => '',
                'ai_attribution' => $this->extractAttribution($perCommitLinks[$sha]),
                'files_changed' => $files,
            ];
        }

        return [$prompts, $perCommitLinks];
    }

    /**
     * @param  list<string>  $links
     */
    private function extractAttribution(array $links): string
    {
        foreach ($links as $tag) {
            if (str_starts_with($tag, 'ai-attribution:')) {
                return substr($tag, strlen('ai-attribution:'));
            }
        }

        return 'human';
    }

    private function payloadString(EvidenceItem $item, string $key, string $default): string
    {
        $value = $item->payload[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * Bucket key used inside the per-repo evidence-link map for
     * the slugs that apply repo-wide rather than per-SHA (design
     * docs, branch semantics — both are correlated downstream).
     */
    private static function repoLevelBucket(): string
    {
        return '__repo_level__';
    }
}
