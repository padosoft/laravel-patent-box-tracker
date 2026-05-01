<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Classifier;

/**
 * Per-class precision / recall / F1 + macro-F1 for a classifier
 * run against the hand-graded golden set.
 *
 * The "label" treated as the joint key is `phase + is_rd_qualified`,
 * so for example a commit graded `(implementation, true)` and a
 * commit graded `(implementation, false)` count as DIFFERENT
 * classes — the qualified-or-not call carries fiscal weight even
 * when the phase is the same.
 */
final readonly class GoldenSetReport
{
    /**
     * @param  array<string, array{precision: float, recall: float, f1: float, support: int}>  $perClass
     *                                                                                                    Indexed by joint label `<phase>:<true|false>`. `support` is the count of
     *                                                                                                    ground-truth labels in that class.
     * @param  float  $macroF1  Macro-averaged F1 over the classes that have non-zero
     *                          support OR non-zero predictions (zero-support classes
     *                          are dropped to avoid biasing the macro mean).
     * @param  int  $totalPredictions  Number of golden-set entries that had a matching
     *                                 prediction in the supplied predictions list.
     * @param  int  $totalGroundTruth  Total number of entries in the golden-set fixture
     *                                 (including those with no matching prediction).
     * @param  list<string>  $missingPredictions  Golden-set SHAs with no matching
     *                                            prediction. Surfaced so callers can
     *                                            decide whether the run was incomplete.
     */
    public function __construct(
        public array $perClass,
        public float $macroF1,
        public int $totalPredictions,
        public int $totalGroundTruth,
        public array $missingPredictions,
    ) {}
}
