<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Classifier;

use RuntimeException;

/**
 * Thrown when the macro-averaged F1 of a classifier run against
 * the hand-graded golden set falls below the configured release
 * gate (default 0.80 per PLAN-W4 §4.3).
 *
 * Used by the v0.1.0 release gate and by `patent-box:track`
 * runs that opt into the `--validate-golden` flag.
 */
final class GoldenSetThresholdException extends RuntimeException {}
