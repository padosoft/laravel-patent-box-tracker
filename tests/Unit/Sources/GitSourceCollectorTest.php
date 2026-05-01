<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Sources;

use DateTimeImmutable;
use Padosoft\PatentBoxTracker\Sources\CollectorContext;
use Padosoft\PatentBoxTracker\Sources\EvidenceItem;
use Padosoft\PatentBoxTracker\Sources\GitSourceCollector;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class GitSourceCollectorTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__.'/../../fixtures/repos/synthetic-r-and-d.git';

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_dir(self::FIXTURE_PATH)) {
            $this->markTestSkipped(
                'Synthetic git fixture not built. Run tests/fixtures/repos/build-synthetic.sh first.'
            );
        }
    }

    public function test_supports_returns_true_for_a_git_repo(): void
    {
        $collector = new GitSourceCollector;
        $context = $this->makeContext();

        $this->assertTrue($collector->supports($context));
    }

    public function test_supports_returns_false_for_a_non_git_dir(): void
    {
        $collector = new GitSourceCollector;
        $context = new CollectorContext(
            repositoryPath: sys_get_temp_dir(),
            repositoryRole: 'support',
            branch: null,
            periodFrom: new DateTimeImmutable('2026-01-01T00:00:00Z'),
            periodTo: new DateTimeImmutable('2026-12-31T23:59:59Z'),
        );

        $this->assertFalse($collector->supports($context));
    }

    public function test_collect_emits_eight_commits_filtering_two_bot_authors(): void
    {
        $collector = new GitSourceCollector;
        $context = $this->makeContext();

        $items = iterator_to_array($collector->collect($context), false);

        // Synthetic fixture has 10 commits; 2 are bot-authored
        // (dependabot, github-actions). Expect 8 emitted.
        $this->assertCount(8, $items);

        foreach ($items as $item) {
            $this->assertSame(EvidenceItem::KIND_COMMIT, $item->kind);
            $this->assertNotNull($item->sha);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $item->sha);
            $this->assertNotEmpty($item->payload['authorName']);
            $this->assertNotEmpty($item->payload['authorEmail']);
            $this->assertNotEmpty($item->payload['committedAt']);
            $this->assertNotEmpty($item->payload['hashChainSelf']);
            $this->assertNotEmpty($item->payload['hashChainPrev']);
        }
    }

    public function test_excluded_authors_are_filtered_out(): void
    {
        $collector = new GitSourceCollector;
        $context = $this->makeContext();

        $items = iterator_to_array($collector->collect($context), false);

        $authorEmails = array_map(
            static fn (EvidenceItem $i): string => (string) $i->payload['authorEmail'],
            $items,
        );

        foreach ($authorEmails as $email) {
            $this->assertStringNotContainsString('dependabot[bot]', $email);
            $this->assertStringNotContainsString('github-actions[bot]', $email);
            $this->assertStringNotContainsString('renovate[bot]', $email);
        }
    }

    public function test_hash_chain_is_intact_and_reproducible(): void
    {
        $collector = new GitSourceCollector;
        $context = $this->makeContext();

        $first = iterator_to_array($collector->collect($context), false);
        $second = iterator_to_array($collector->collect($context), false);

        $this->assertCount(count($first), $second);

        $prev = '0000000000000000000000000000000000000000000000000000000000000000';
        foreach ($first as $idx => $item) {
            $this->assertSame($prev, $item->payload['hashChainPrev']);
            $expected = hash('sha256', $prev.$item->payload['sha']);
            $this->assertSame($expected, $item->payload['hashChainSelf']);
            $prev = (string) $item->payload['hashChainSelf'];

            // Reproducibility — the second walk emits the same chain at
            // the same position.
            $this->assertSame(
                $item->payload['hashChainSelf'],
                $second[$idx]->payload['hashChainSelf'],
            );
        }
    }

    public function test_collect_emits_in_chronological_order(): void
    {
        $collector = new GitSourceCollector;
        $context = $this->makeContext();

        $items = iterator_to_array($collector->collect($context), false);
        $this->assertNotEmpty($items);

        $previousTs = 0;
        foreach ($items as $item) {
            $ts = strtotime((string) $item->payload['committedAt']);
            $this->assertNotFalse($ts);
            $this->assertGreaterThanOrEqual($previousTs, $ts);
            $previousTs = $ts;
        }
    }

    public function test_payload_contains_files_changed_metadata(): void
    {
        $collector = new GitSourceCollector;
        $context = $this->makeContext();

        $items = iterator_to_array($collector->collect($context), false);
        $this->assertNotEmpty($items);

        // Every commit in the synthetic fixture creates exactly one file.
        foreach ($items as $item) {
            $files = $item->payload['filesChanged'];
            $this->assertIsArray($files);
            $this->assertGreaterThanOrEqual(1, count($files));
            $this->assertArrayHasKey('path', $files[0]);
            $this->assertArrayHasKey('insertions', $files[0]);
            $this->assertArrayHasKey('deletions', $files[0]);
        }
    }

    public function test_collector_name_is_stable(): void
    {
        $this->assertSame('git-source', (new GitSourceCollector)->name());
    }

    private function makeContext(): CollectorContext
    {
        return new CollectorContext(
            repositoryPath: self::FIXTURE_PATH,
            repositoryRole: 'primary_ip',
            branch: null,
            periodFrom: new DateTimeImmutable('2026-01-01T00:00:00Z'),
            periodTo: new DateTimeImmutable('2026-12-31T23:59:59Z'),
            excludedAuthors: [
                'dependabot[bot]',
                'renovate[bot]',
                'github-actions[bot]',
            ],
        );
    }
}
