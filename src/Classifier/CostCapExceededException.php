<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Classifier;

use RuntimeException;

/**
 * Thrown by {@see CostCapGuard::abortIfExceeded()} when the
 * pre-flight cost projection of a classifier run exceeds the
 * configured `cost_cap_eur_per_run` (PLAN-W4 §4.4).
 */
final class CostCapExceededException extends RuntimeException {}
