<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Config;

use RuntimeException;

/**
 * Thrown by {@see CrossRepoConfigValidator} when a YAML config file
 * fails schema validation.
 *
 * The exception message MUST identify the offending field with a
 * dotted-path locator (e.g. `repositories[2].role`) so an operator
 * can correct the YAML without re-reading the schema. Catch sites
 * surface the message verbatim — no extra wrapping.
 */
final class CrossRepoConfigException extends RuntimeException {}
