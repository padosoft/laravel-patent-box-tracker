<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Sources;

use Padosoft\PatentBoxTracker\Sources\BranchSemanticsCollector;
use Padosoft\PatentBoxTracker\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BranchSemanticsCollectorTest extends TestCase
{
    /**
     * Branch-name → expected (phase, qualified, prefix, versionCycle, subtask).
     *
     * @return iterable<string, array{0:string,1:string,2:bool,3:string,4:?string,5:?string}>
     */
    public static function branchNames(): iterable
    {
        yield 'feature/v4.0-W3.2-vercel-chat-migration' => [
            'feature/v4.0-W3.2-vercel-chat-migration',
            BranchSemanticsCollector::PHASE_DEVELOPMENT,
            true,
            'feature',
            'v4.0',
            'W3.2',
        ];

        yield 'feature/v4.0/W4.B.1/collectors' => [
            'feature/v4.0/W4.B.1/collectors',
            BranchSemanticsCollector::PHASE_DEVELOPMENT,
            true,
            'feature',
            'v4.0',
            'W4.B.1',
        ];

        yield 'feature/v4.0' => [
            'feature/v4.0',
            BranchSemanticsCollector::PHASE_DEVELOPMENT,
            true,
            'feature',
            'v4.0',
            null,
        ];

        yield 'feature/enh-faster-tree-search' => [
            'feature/enh-faster-tree-search',
            BranchSemanticsCollector::PHASE_ENHANCEMENT,
            true,
            'feature',
            null,
            null,
        ];

        yield 'feature/something-experimental' => [
            'feature/something-experimental',
            BranchSemanticsCollector::PHASE_DEVELOPMENT,
            true,
            'feature',
            null,
            null,
        ];

        yield 'fix/missing-semicolon' => [
            'fix/missing-semicolon',
            BranchSemanticsCollector::PHASE_IMPLEMENTATION,
            true,
            'fix',
            null,
            null,
        ];

        yield 'chore/bump-deps' => [
            'chore/bump-deps',
            BranchSemanticsCollector::PHASE_HYGIENE,
            false,
            'chore',
            null,
            null,
        ];

        yield 'ci/fix-matrix' => [
            'ci/fix-matrix',
            BranchSemanticsCollector::PHASE_INFRASTRUCTURE,
            false,
            'ci',
            null,
            null,
        ];

        yield 'docs/sample-update' => [
            'docs/sample-update',
            BranchSemanticsCollector::PHASE_DOCUMENTATION,
            true,
            'docs',
            null,
            null,
        ];

        yield 'main (uncategorized)' => [
            'main',
            BranchSemanticsCollector::PHASE_UNKNOWN,
            false,
            '',
            null,
            null,
        ];
    }

    #[DataProvider('branchNames')]
    public function test_classify_branch(
        string $branch,
        string $expectedPhase,
        bool $expectedQualified,
        string $expectedPrefix,
        ?string $expectedVersion,
        ?string $expectedSubtask,
    ): void {
        $result = BranchSemanticsCollector::classifyBranch($branch);

        $this->assertSame($expectedPhase, $result['phase'], "phase for {$branch}");
        $this->assertSame($expectedQualified, $result['qualified'], "qualified for {$branch}");
        $this->assertSame($expectedPrefix, $result['prefix'], "prefix for {$branch}");
        $this->assertSame($expectedVersion, $result['versionCycle'], "versionCycle for {$branch}");
        $this->assertSame($expectedSubtask, $result['subtask'], "subtask for {$branch}");
    }

    public function test_collector_name_is_stable(): void
    {
        $this->assertSame('branch-semantics', (new BranchSemanticsCollector)->name());
    }
}
