<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Classifier;

use Padosoft\PatentBoxTracker\Classifier\CommitClassification;
use Padosoft\PatentBoxTracker\Classifier\Phase;
use PHPUnit\Framework\TestCase;

final class CommitClassificationTest extends TestCase
{
    public function test_to_array_round_trips_every_field(): void
    {
        $classification = new CommitClassification(
            sha: 'abcdef0123456789abcdef0123456789abcdef01',
            phase: Phase::Implementation,
            isRdQualified: true,
            rdQualificationConfidence: 0.92,
            rationale: 'Realises a designed component, qualifies under Patent Box.',
            rejectedPhase: Phase::Design,
            evidenceUsed: ['plan:PLAN-W3', 'adr:0007'],
        );

        $array = $classification->toArray();

        $this->assertSame('abcdef0123456789abcdef0123456789abcdef01', $array['sha']);
        $this->assertSame('implementation', $array['phase']);
        $this->assertTrue($array['is_rd_qualified']);
        $this->assertSame(0.92, $array['rd_qualification_confidence']);
        $this->assertSame('Realises a designed component, qualifies under Patent Box.', $array['rationale']);
        $this->assertSame('design', $array['rejected_phase']);
        $this->assertSame(['plan:PLAN-W3', 'adr:0007'], $array['evidence_used']);
    }

    public function test_to_array_emits_null_for_absent_rejected_phase(): void
    {
        $classification = new CommitClassification(
            sha: '0000000000000000000000000000000000000000',
            phase: Phase::NonQualified,
            isRdQualified: false,
            rdQualificationConfidence: 0.95,
            rationale: 'Pure CI plumbing.',
            rejectedPhase: null,
            evidenceUsed: [],
        );

        $array = $classification->toArray();

        $this->assertNull($array['rejected_phase']);
        $this->assertSame([], $array['evidence_used']);
    }

    public function test_dto_is_readonly_and_immutable(): void
    {
        $classification = new CommitClassification(
            sha: 'abcdef0123456789abcdef0123456789abcdef01',
            phase: Phase::Research,
            isRdQualified: true,
            rdQualificationConfidence: 0.7,
            rationale: 'Exploratory prototyping.',
            rejectedPhase: null,
            evidenceUsed: [],
        );

        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/Cannot modify readonly property/');

        // @phpstan-ignore-next-line — intentional readonly violation under test.
        $classification->phase = Phase::NonQualified;
    }
}
