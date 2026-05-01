<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Classifier;

use RuntimeException;

/**
 * Thrown when the LLM response is malformed and cannot be parsed
 * into a {@see CommitClassification[]} array.
 *
 * The exception message intentionally embeds a (truncated) preview
 * of the raw response so a developer running the suite can see
 * what the LLM actually returned without re-running the call.
 */
final class ClassifierResponseException extends RuntimeException {}
