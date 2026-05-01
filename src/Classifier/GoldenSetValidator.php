<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Classifier;

use InvalidArgumentException;
use JsonException;

/**
 * Loads the hand-graded golden set and computes precision /
 * recall / F1 against a list of {@see CommitClassification}
 * predictions (PLAN-W4 §4.3 release gate).
 *
 * The joint label is `phase + is_rd_qualified` — we evaluate
 * BOTH the phase choice and the qualified-or-not decision in a
 * single F1 score. This is intentional: classifying an
 * `implementation` commit as `documentation` is a less-bad
 * mistake than flipping a `qualified` commit to `non_qualified`
 * (which loses fiscal money), but both are wrong and the
 * release gate must catch both.
 *
 * The golden-set fixture path defaults to
 * `tests/fixtures/golden-classifications.json` relative to the
 * repository root; override via the constructor for custom
 * fixtures.
 */
final class GoldenSetValidator
{
    private const DEFAULT_FIXTURE_RELATIVE = 'tests/fixtures/golden-classifications.json';

    /**
     * @var list<array{sha: string, phase: Phase, is_rd_qualified: bool, rationale: string}>
     */
    private array $groundTruth;

    public function __construct(?string $fixturePath = null)
    {
        $path = $fixturePath ?? self::defaultFixturePath();
        $this->groundTruth = $this->loadFixture($path);
    }

    /**
     * Compute precision / recall / F1 against the supplied
     * predictions. Predictions for SHAs not in the golden set
     * are silently ignored — this lets the validator run on a
     * superset (e.g. a full classifier run produced 200
     * classifications, only 50 of which are in the golden set).
     *
     * @param  list<CommitClassification>  $predictions
     */
    public function validate(array $predictions): GoldenSetReport
    {
        $byShaPrediction = [];
        foreach ($predictions as $prediction) {
            $byShaPrediction[$prediction->sha] = $prediction;
        }

        // Build the contingency: for every (ground-truth label,
        // predicted label) pair we count how many SHAs landed
        // there. From the contingency we derive TP / FP / FN per
        // class.
        $tp = [];
        $fp = [];
        $fn = [];
        $support = [];
        $missingPredictions = [];

        $matchedCount = 0;
        $evaluatedPredictions = 0;

        foreach ($this->groundTruth as $entry) {
            $sha = $entry['sha'];
            $truthLabel = self::jointLabel($entry['phase'], $entry['is_rd_qualified']);
            $support[$truthLabel] = ($support[$truthLabel] ?? 0) + 1;

            if (! isset($byShaPrediction[$sha])) {
                $missingPredictions[] = $sha;
                $fn[$truthLabel] = ($fn[$truthLabel] ?? 0) + 1;

                continue;
            }

            $matchedCount++;
            $evaluatedPredictions++;

            $prediction = $byShaPrediction[$sha];
            $predLabel = self::jointLabel($prediction->phase, $prediction->isRdQualified);

            if ($predLabel === $truthLabel) {
                $tp[$truthLabel] = ($tp[$truthLabel] ?? 0) + 1;
            } else {
                $fn[$truthLabel] = ($fn[$truthLabel] ?? 0) + 1;
                $fp[$predLabel] = ($fp[$predLabel] ?? 0) + 1;
            }
        }

        $perClass = $this->computePerClass($tp, $fp, $fn, $support);
        $macroF1 = $this->macroF1($perClass);

        return new GoldenSetReport(
            perClass: $perClass,
            macroF1: $macroF1,
            totalPredictions: $evaluatedPredictions,
            totalGroundTruth: $matchedCount,
            missingPredictions: $missingPredictions,
        );
    }

    /**
     * Throw when the macro-F1 falls below the threshold.
     *
     * @param  list<CommitClassification>  $predictions
     *
     * @throws GoldenSetThresholdException
     */
    public function enforce(array $predictions, float $minMacroF1): void
    {
        $report = $this->validate($predictions);

        if ($report->macroF1 < $minMacroF1) {
            throw new GoldenSetThresholdException(sprintf(
                'GoldenSetValidator: macro-F1 %.4f is below the required threshold %.4f. '
                .'Predictions evaluated: %d. Missing: %d.',
                $report->macroF1,
                $minMacroF1,
                $report->totalPredictions,
                count($report->missingPredictions),
            ));
        }
    }

    /**
     * @return list<array{sha: string, phase: Phase, is_rd_qualified: bool, rationale: string}>
     */
    public function groundTruth(): array
    {
        return $this->groundTruth;
    }

    /**
     * @return list<array{sha: string, phase: Phase, is_rd_qualified: bool, rationale: string}>
     */
    private function loadFixture(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException(sprintf(
                'GoldenSetValidator: fixture "%s" does not exist or is unreadable.',
                $path,
            ));
        }

        $raw = (string) file_get_contents($path);
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(sprintf(
                'GoldenSetValidator: fixture "%s" is not valid JSON: %s',
                $path,
                $e->getMessage(),
            ), previous: $e);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException(sprintf(
                'GoldenSetValidator: fixture "%s" must be a JSON array of entries.',
                $path,
            ));
        }

        $entries = [];
        foreach ($decoded as $idx => $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException(sprintf(
                    'GoldenSetValidator: fixture entry #%d is not an object.',
                    $idx,
                ));
            }

            $sha = $row['sha'] ?? null;
            $phase = $row['phase'] ?? null;
            $qualified = $row['is_rd_qualified'] ?? null;
            $rationale = $row['rationale'] ?? '';

            if (! is_string($sha) || ! preg_match('/^[a-f0-9]{6,40}$/i', $sha)) {
                throw new InvalidArgumentException(sprintf(
                    'GoldenSetValidator: fixture entry #%d has invalid sha "%s".',
                    $idx,
                    is_scalar($sha) ? (string) $sha : get_debug_type($sha),
                ));
            }
            if (! is_string($phase)) {
                throw new InvalidArgumentException(sprintf(
                    'GoldenSetValidator: fixture entry #%d has non-string phase.',
                    $idx,
                ));
            }
            if (! is_bool($qualified)) {
                throw new InvalidArgumentException(sprintf(
                    'GoldenSetValidator: fixture entry #%d has non-bool is_rd_qualified.',
                    $idx,
                ));
            }

            $entries[] = [
                'sha' => strtolower($sha),
                'phase' => Phase::fromString($phase),
                'is_rd_qualified' => $qualified,
                'rationale' => is_string($rationale) ? $rationale : '',
            ];
        }

        return $entries;
    }

    /**
     * @param  array<string, int>  $tp
     * @param  array<string, int>  $fp
     * @param  array<string, int>  $fn
     * @param  array<string, int>  $support
     * @return array<string, array{precision: float, recall: float, f1: float, support: int}>
     */
    private function computePerClass(array $tp, array $fp, array $fn, array $support): array
    {
        $labels = array_unique(array_merge(
            array_keys($tp),
            array_keys($fp),
            array_keys($fn),
            array_keys($support),
        ));
        sort($labels);

        $result = [];
        foreach ($labels as $label) {
            $truePos = $tp[$label] ?? 0;
            $falsePos = $fp[$label] ?? 0;
            $falseNeg = $fn[$label] ?? 0;
            $sup = $support[$label] ?? 0;

            $precision = ($truePos + $falsePos) === 0
                ? 0.0
                : $truePos / ($truePos + $falsePos);
            $recall = ($truePos + $falseNeg) === 0
                ? 0.0
                : $truePos / ($truePos + $falseNeg);
            $f1 = ($precision + $recall) === 0.0
                ? 0.0
                : (2 * $precision * $recall) / ($precision + $recall);

            $result[$label] = [
                'precision' => round($precision, 6),
                'recall' => round($recall, 6),
                'f1' => round($f1, 6),
                'support' => $sup,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, array{precision: float, recall: float, f1: float, support: int}>  $perClass
     */
    private function macroF1(array $perClass): float
    {
        // Average over classes that have non-zero support OR
        // non-zero predictions (i.e. classes that actually
        // appeared in the evaluation). Pure-zero classes are
        // dropped — they would otherwise pull the mean down to
        // zero unfairly when the golden set never sees that label.
        $relevant = array_filter(
            $perClass,
            static fn (array $row): bool => $row['support'] > 0,
        );

        if ($relevant === []) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($relevant as $row) {
            $sum += $row['f1'];
        }

        return round($sum / count($relevant), 6);
    }

    private static function jointLabel(Phase $phase, bool $isQualified): string
    {
        return $phase->value.':'.($isQualified ? 'true' : 'false');
    }

    private static function defaultFixturePath(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.self::DEFAULT_FIXTURE_RELATIVE;
    }
}
