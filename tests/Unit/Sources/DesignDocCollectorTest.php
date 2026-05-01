<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Sources;

use DateTimeImmutable;
use Padosoft\PatentBoxTracker\Sources\CollectorContext;
use Padosoft\PatentBoxTracker\Sources\DesignDocCollector;
use Padosoft\PatentBoxTracker\Sources\EvidenceItem;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class DesignDocCollectorTest extends TestCase
{
    private string $synthRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->synthRoot = sys_get_temp_dir().'/patent-box-design-doc-fixture-'.uniqid();
        $this->buildSyntheticTree($this->synthRoot);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->synthRoot);
        parent::tearDown();
    }

    public function test_supports_returns_true_for_existing_dir(): void
    {
        $collector = new DesignDocCollector;
        $context = $this->makeContext($this->synthRoot);

        $this->assertTrue($collector->supports($context));
    }

    public function test_supports_returns_false_for_missing_dir(): void
    {
        $collector = new DesignDocCollector;
        $context = new CollectorContext(
            repositoryPath: '/path/that/definitely/does/not/exist/'.uniqid(),
            repositoryRole: 'support',
            branch: null,
            periodFrom: new DateTimeImmutable('2026-01-01T00:00:00Z'),
            periodTo: new DateTimeImmutable('2026-12-31T23:59:59Z'),
        );

        $this->assertFalse($collector->supports($context));
    }

    public function test_collect_emits_one_item_per_design_doc(): void
    {
        $collector = new DesignDocCollector;
        $context = $this->makeContext($this->synthRoot);

        $items = iterator_to_array($collector->collect($context), false);

        // We seeded: PLAN-W4-x.md, ADR-0007.md, SPEC-foo.md,
        // lessons-learned.md (4 design docs). README.md is NOT a design
        // doc and should be skipped.
        $slugs = array_map(
            static fn (EvidenceItem $i): string => (string) $i->payload['slug'],
            $items,
        );

        $this->assertContains('PLAN-W4-x', $slugs);
        $this->assertContains('ADR-0007', $slugs);
        $this->assertContains('SPEC-foo', $slugs);
        $this->assertContains('lessons-learned', $slugs);
        $this->assertNotContains('README', $slugs);
        $this->assertCount(4, $items);
    }

    public function test_design_doc_payload_has_expected_keys(): void
    {
        $collector = new DesignDocCollector;
        $context = $this->makeContext($this->synthRoot);

        $items = iterator_to_array($collector->collect($context), false);
        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $this->assertSame(EvidenceItem::KIND_DESIGN_DOC_LINK, $item->kind);
            $this->assertNull($item->sha);
            $this->assertArrayHasKey('path', $item->payload);
            $this->assertArrayHasKey('slug', $item->payload);
            $this->assertArrayHasKey('title', $item->payload);
            $this->assertArrayHasKey('correlatedCommits', $item->payload);
            $this->assertIsArray($item->payload['correlatedCommits']);
        }
    }

    public function test_title_is_extracted_from_first_h1(): void
    {
        $collector = new DesignDocCollector;
        $context = $this->makeContext($this->synthRoot);

        $items = iterator_to_array($collector->collect($context), false);

        $titles = [];
        foreach ($items as $item) {
            $titles[(string) $item->payload['slug']] = (string) $item->payload['title'];
        }

        $this->assertSame('PLAN W4 x', $titles['PLAN-W4-x']);
        $this->assertSame('ADR 0007', $titles['ADR-0007']);
    }

    public function test_collector_name_is_stable(): void
    {
        $this->assertSame('design-doc', (new DesignDocCollector)->name());
    }

    public function test_emission_order_is_deterministic(): void
    {
        $collector = new DesignDocCollector;
        $context = $this->makeContext($this->synthRoot);

        $first = array_map(
            static fn (EvidenceItem $i): string => (string) $i->payload['path'],
            iterator_to_array($collector->collect($context), false),
        );
        $second = array_map(
            static fn (EvidenceItem $i): string => (string) $i->payload['path'],
            iterator_to_array($collector->collect($context), false),
        );

        $this->assertSame($first, $second);
    }

    private function makeContext(string $root): CollectorContext
    {
        return new CollectorContext(
            repositoryPath: $root,
            repositoryRole: 'primary_ip',
            branch: null,
            periodFrom: new DateTimeImmutable('2026-01-01T00:00:00Z'),
            periodTo: new DateTimeImmutable('2026-12-31T23:59:59Z'),
        );
    }

    private function buildSyntheticTree(string $root): void
    {
        $files = [
            'docs/v4-platform/PLAN-W4-x.md' => "# PLAN W4 x\n\nBody.\n",
            'docs/adr/ADR-0007.md' => "# ADR 0007\n\nDecision.\n",
            'docs/superpowers/specs/SPEC-foo.md' => "# SPEC foo\n\nBody.\n",
            'docs/plans/lessons-learned.md' => "# Lessons learned\n\nWhat we learned.\n",
            'docs/README.md' => "# README\n\nNot a design doc.\n",
        ];

        foreach ($files as $rel => $content) {
            $abs = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $dir = dirname($abs);
            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new \RuntimeException(sprintf('Failed to mkdir %s', $dir));
            }
            file_put_contents($abs, $content);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($dir);
    }
}
