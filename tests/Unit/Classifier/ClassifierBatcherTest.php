<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Classifier;

use Illuminate\Support\Facades\Http;
use Padosoft\PatentBoxTracker\Classifier\ClassifierBatcher;
use Padosoft\PatentBoxTracker\Classifier\ClassifierPrompts;
use Padosoft\PatentBoxTracker\Classifier\CommitClassifier;
use Padosoft\PatentBoxTracker\Sources\EvidenceItem;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class ClassifierBatcherTest extends TestCase
{
    public function test_thirty_five_commits_fan_out_to_two_batches_of_twenty_then_fifteen(): void
    {
        $repoPath = '/fake/repo';
        $items = $this->buildCommitItems($repoPath, 35);

        $this->fakeAnthropicSequence($repoPath);

        $batcher = new ClassifierBatcher(
            classifier: new CommitClassifier(
                prompts: new ClassifierPrompts,
                driver: 'anthropic',
                model: 'claude-sonnet-4-6',
                seed: 0,
                timeoutSeconds: 30,
            ),
            batchSize: 20,
        );

        $results = iterator_to_array($batcher->classifyAll($items), false);

        $this->assertCount(35, $results);
        Http::assertSentCount(2);

        // Yielded order matches input order (first commit yielded first).
        $firstSha = sprintf('%040x', 0);
        $lastSha = sprintf('%040x', 34);
        $this->assertSame($firstSha, $results[0]->sha);
        $this->assertSame($lastSha, $results[34]->sha);
    }

    public function test_invalid_batch_size_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClassifierBatcher(
            classifier: new CommitClassifier(
                prompts: new ClassifierPrompts,
                driver: 'anthropic',
                model: 'claude-sonnet-4-6',
                seed: 0,
            ),
            batchSize: 0,
        );
    }

    public function test_non_commit_evidence_kinds_are_ignored_for_classification(): void
    {
        $repoPath = '/fake/repo';
        $items = [
            ...$this->buildCommitItems($repoPath, 3),
            new EvidenceItem(
                kind: EvidenceItem::KIND_DESIGN_DOC_LINK,
                repositoryPath: $repoPath,
                sha: null,
                payload: ['kind' => 'plan', 'slug' => 'PLAN-W3'],
            ),
            new EvidenceItem(
                kind: EvidenceItem::KIND_BRANCH_SEMANTIC,
                repositoryPath: $repoPath,
                sha: null,
                payload: ['branch' => 'feature/v4.0-W3.1'],
            ),
        ];

        $this->fakeAnthropicSequence($repoPath, batchCount: 1, batchSizes: [3]);

        $batcher = new ClassifierBatcher(
            classifier: new CommitClassifier(
                prompts: new ClassifierPrompts,
                driver: 'anthropic',
                model: 'claude-sonnet-4-6',
                seed: 0,
            ),
            batchSize: 20,
        );

        $results = iterator_to_array($batcher->classifyAll($items), false);

        $this->assertCount(3, $results);
        Http::assertSentCount(1);
    }

    /**
     * @return list<EvidenceItem>
     */
    private function buildCommitItems(string $repoPath, int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $sha = sprintf('%040x', $i);
            $items[] = new EvidenceItem(
                kind: EvidenceItem::KIND_COMMIT,
                repositoryPath: $repoPath,
                sha: $sha,
                payload: [
                    'sha' => $sha,
                    'subject' => "feat: synthetic commit {$i}",
                    'body' => '',
                    'authorEmail' => 'lorenzo.padovani@padosoft.com',
                    'committedAt' => '2026-04-01T10:00:00Z',
                    'filesChanged' => [
                        ['path' => "src/file_{$i}.php", 'insertions' => 1, 'deletions' => 0],
                    ],
                ],
            );
        }

        return $items;
    }

    /**
     * Build a sequence of canned Anthropic responses that map back to
     * the commit shas produced by {@see buildCommitItems()} so the
     * classifier can match them by SHA.
     *
     * @param  list<int>|null  $batchSizes  Optional per-batch sizes; defaults to the
     *                                      symmetric 20/15 split for 35 commits.
     */
    private function fakeAnthropicSequence(string $repoPath, int $batchCount = 2, ?array $batchSizes = null): void
    {
        $batchSizes ??= [20, 15];

        $responses = [];
        $cursor = 0;
        for ($b = 0; $b < $batchCount; $b++) {
            $size = $batchSizes[$b] ?? 0;
            $entries = [];
            for ($k = 0; $k < $size; $k++) {
                $sha = sprintf('%040x', $cursor++);
                $entries[] = [
                    'sha' => $sha,
                    'phase' => 'implementation',
                    'is_rd_qualified' => true,
                    'rd_qualification_confidence' => 0.8,
                    'rationale' => 'Synthetic batch test classification.',
                    'rejected_phase' => null,
                    'evidence_used' => [],
                ];
            }

            $responses[] = Http::response([
                'id' => 'msg_test_'.$b,
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'text', 'text' => json_encode(['classifications' => $entries], JSON_THROW_ON_ERROR)],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 600, 'output_tokens' => 80],
            ], 200);
        }

        $sequence = Http::sequence();
        foreach ($responses as $response) {
            $sequence->pushResponse($response);
        }

        Http::fake([
            'api.anthropic.com/*' => $sequence,
        ]);
    }
}
