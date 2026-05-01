<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Classifier;

use Padosoft\PatentBoxTracker\Classifier\CommitClassification;
use Padosoft\PatentBoxTracker\Classifier\GoldenSetThresholdException;
use Padosoft\PatentBoxTracker\Classifier\GoldenSetValidator;
use Padosoft\PatentBoxTracker\Classifier\Phase;
use PHPUnit\Framework\TestCase;

final class GoldenSetValidatorTest extends TestCase
{
    private const SYNTHETIC_FIXTURE = __DIR__.'/../../fixtures/golden-classifications-test.json';

    public function test_validate_yields_macro_f1_at_or_above_zero_eight_for_eight_of_ten_correct(): void
    {
        $validator = new GoldenSetValidator(self::SYNTHETIC_FIXTURE);
        $predictions = $this->buildPredictionsWithTwoCrossErrors();

        $report = $validator->validate($predictions);

        // 2 errors: sha 3 (truth implementation:true → predicted design:true)
        //           sha 4 (truth design:true → predicted implementation:true)
        // implementation:true F1 = 2/3 ≈ 0.6667
        // design:true F1 = 0.5
        // validation, documentation, research, non_qualified all F1 = 1.0
        // Macro = (0.667 + 0.5 + 1 + 1 + 1 + 1) / 6 ≈ 0.8611
        $this->assertEqualsWithDelta(0.8611, $report->macroF1, 0.005);
        $this->assertSame(10, $report->totalGroundTruth);
        $this->assertSame(10, $report->totalPredictions);
        $this->assertSame([], $report->missingPredictions);

        // Spot-check the per-class breakdown.
        $this->assertArrayHasKey('implementation:true', $report->perClass);
        $this->assertArrayHasKey('design:true', $report->perClass);
        $this->assertEqualsWithDelta(2 / 3, $report->perClass['implementation:true']['f1'], 0.001);
        $this->assertEqualsWithDelta(0.5, $report->perClass['design:true']['f1'], 0.001);
        $this->assertSame(1.0, $report->perClass['validation:true']['f1']);
        $this->assertSame(1.0, $report->perClass['documentation:true']['f1']);
    }

    public function test_enforce_throws_when_threshold_above_actual_macro_f1(): void
    {
        $validator = new GoldenSetValidator(self::SYNTHETIC_FIXTURE);
        $predictions = $this->buildPredictionsWithTwoCrossErrors();

        $this->expectException(GoldenSetThresholdException::class);
        $this->expectExceptionMessageMatches('/macro-F1 [\d.]+ is below the required threshold 0\.9000/');

        $validator->enforce($predictions, 0.90);
    }

    public function test_enforce_passes_when_threshold_below_actual_macro_f1(): void
    {
        $validator = new GoldenSetValidator(self::SYNTHETIC_FIXTURE);
        $predictions = $this->buildPredictionsWithTwoCrossErrors();

        $validator->enforce($predictions, 0.75);

        // No exception → success.
        $this->assertTrue(true);
    }

    public function test_perfect_predictions_score_macro_f1_of_one(): void
    {
        $validator = new GoldenSetValidator(self::SYNTHETIC_FIXTURE);
        $perfect = [];
        foreach ($validator->groundTruth() as $entry) {
            $perfect[] = new CommitClassification(
                sha: $entry['sha'],
                phase: $entry['phase'],
                isRdQualified: $entry['is_rd_qualified'],
                rdQualificationConfidence: 1.0,
                rationale: 'perfect prediction',
                rejectedPhase: null,
                evidenceUsed: [],
            );
        }

        $report = $validator->validate($perfect);

        $this->assertSame(1.0, $report->macroF1);
    }

    public function test_missing_predictions_are_listed_and_count_as_misses(): void
    {
        $validator = new GoldenSetValidator(self::SYNTHETIC_FIXTURE);
        $partial = [
            new CommitClassification(
                sha: '1111111111111111111111111111111111111111',
                phase: Phase::Implementation,
                isRdQualified: true,
                rdQualificationConfidence: 0.9,
                rationale: 'only one prediction',
                rejectedPhase: null,
                evidenceUsed: [],
            ),
        ];

        $report = $validator->validate($partial);

        $this->assertCount(9, $report->missingPredictions);
        $this->assertContains('2222222222222222222222222222222222222222', $report->missingPredictions);
    }

    public function test_invalid_fixture_path_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist or is unreadable/');

        new GoldenSetValidator('/non/existent/path.json');
    }

    public function test_production_golden_fixture_is_well_formed(): void
    {
        // Validates the actual tests/fixtures/golden-classifications.json — the v0.1
        // 10-commit hand-graded set we ship with the package.
        $validator = new GoldenSetValidator;

        $entries = $validator->groundTruth();
        $this->assertCount(10, $entries);

        $phasesSeen = [];
        foreach ($entries as $entry) {
            $phasesSeen[$entry['phase']->value] = true;
        }

        // Sanity: all six phases must appear at least once.
        foreach (['research', 'design', 'implementation', 'validation', 'documentation', 'non_qualified'] as $phase) {
            $this->assertArrayHasKey($phase, $phasesSeen, "phase '{$phase}' is missing from golden-classifications.json");
        }
    }

    /**
     * @return list<CommitClassification>
     */
    private function buildPredictionsWithTwoCrossErrors(): array
    {
        $truthByPhase = [
            '1111111111111111111111111111111111111111' => Phase::Implementation,
            '2222222222222222222222222222222222222222' => Phase::Implementation,
            '3333333333333333333333333333333333333333' => Phase::Design, // ERROR — truth is implementation
            '4444444444444444444444444444444444444444' => Phase::Implementation, // ERROR — truth is design
            '5555555555555555555555555555555555555555' => Phase::Design,
            '6666666666666666666666666666666666666666' => Phase::Validation,
            '7777777777777777777777777777777777777777' => Phase::Documentation,
            '8888888888888888888888888888888888888888' => Phase::Research,
            '9999999999999999999999999999999999999999' => Phase::NonQualified,
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => Phase::NonQualified,
        ];

        $predictions = [];
        foreach ($truthByPhase as $sha => $phase) {
            $predictions[] = new CommitClassification(
                sha: $sha,
                phase: $phase,
                isRdQualified: $phase !== Phase::NonQualified,
                rdQualificationConfidence: 0.9,
                rationale: 'synthetic prediction',
                rejectedPhase: null,
                evidenceUsed: [],
            );
        }

        return $predictions;
    }
}
