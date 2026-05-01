<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Sources;

use InvalidArgumentException;
use Padosoft\PatentBoxTracker\Sources\AiAttributionExtractor;
use Padosoft\PatentBoxTracker\Sources\BranchSemanticsCollector;
use Padosoft\PatentBoxTracker\Sources\CollectorContext;
use Padosoft\PatentBoxTracker\Sources\CollectorRegistry;
use Padosoft\PatentBoxTracker\Sources\DesignDocCollector;
use Padosoft\PatentBoxTracker\Sources\EvidenceCollector;
use Padosoft\PatentBoxTracker\Sources\GitSourceCollector;
use Padosoft\PatentBoxTracker\Tests\TestCase;

/**
 * Always-true collector used to provoke the supports() mutex.
 */
final class AlwaysSupportsA implements EvidenceCollector
{
    public function name(): string
    {
        return 'always-a';
    }

    public function supports(CollectorContext $context): bool
    {
        return true;
    }

    public function collect(CollectorContext $context): iterable
    {
        return [];
    }
}

final class AlwaysSupportsB implements EvidenceCollector
{
    public function name(): string
    {
        return 'always-b';
    }

    public function supports(CollectorContext $context): bool
    {
        return true;
    }

    public function collect(CollectorContext $context): iterable
    {
        return [];
    }
}

/**
 * Not an EvidenceCollector — used to assert the FQCN check.
 */
final class NotACollector
{
    public function name(): string
    {
        return 'not-a-collector';
    }
}

final class CollectorRegistryTest extends TestCase
{
    public function test_registers_four_canonical_collectors_without_overlap(): void
    {
        $registry = new CollectorRegistry([
            GitSourceCollector::class,
            AiAttributionExtractor::class,
            DesignDocCollector::class,
            BranchSemanticsCollector::class,
        ]);

        $registry->validate();

        $this->assertCount(4, $registry->collectors());
    }

    public function test_throws_when_a_registered_class_is_not_an_evidence_collector(): void
    {
        $registry = new CollectorRegistry([
            GitSourceCollector::class,
            NotACollector::class, // @phpstan-ignore-line — intentional misregistration
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must implement.*EvidenceCollector/');

        $registry->validate();
    }

    public function test_throws_when_a_registered_class_does_not_exist(): void
    {
        $registry = new CollectorRegistry([
            'Padosoft\\PatentBoxTracker\\Sources\\NonExistentCollector', // @phpstan-ignore-line
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $registry->validate();
    }

    public function test_throws_when_two_collectors_overlap_on_supports(): void
    {
        $registry = new CollectorRegistry([
            AlwaysSupportsA::class,
            AlwaysSupportsB::class,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/overlap is forbidden/');

        $registry->validate();
    }

    public function test_overlap_exemption_is_honoured_for_ai_attribution_and_git_source(): void
    {
        // The AiAttributionExtractor declares overlapsBy() = [GitSourceCollector::class].
        // Registering both must NOT trigger the mutex.
        $registry = new CollectorRegistry([
            GitSourceCollector::class,
            AiAttributionExtractor::class,
        ]);

        // Should not throw.
        $registry->validate();

        $this->assertCount(2, $registry->collectors());
    }

    public function test_dispatch_yields_no_items_when_no_collector_supports_the_context(): void
    {
        $registry = new CollectorRegistry([
            // GitSourceCollector + BranchSemanticsCollector both return
            // false on a non-git directory; DesignDocCollector returns
            // true on any existing dir but emits no items if there are
            // no design docs present.
            GitSourceCollector::class,
            BranchSemanticsCollector::class,
        ]);

        $context = new CollectorContext(
            repositoryPath: sys_get_temp_dir(),
            repositoryRole: 'support',
            branch: null,
            periodFrom: new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            periodTo: new \DateTimeImmutable('2026-12-31T23:59:59Z'),
        );

        $items = iterator_to_array($registry->dispatch($context), false);

        $this->assertSame([], $items);
    }
}
