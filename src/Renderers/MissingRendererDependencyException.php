<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Renderers;

use RuntimeException;

/**
 * Thrown when neither Browsershot nor DomPDF is installed in the
 * consumer environment yet a PDF render was requested. Both engines
 * are declared as `require-dev` (Browsershot) / `suggest` (DomPDF) on
 * the package so consumers can choose their PDF stack — but the JSON
 * sidecar pathway works without either.
 *
 * The exception message lists the install commands so the consumer
 * can recover without grepping the README.
 */
final class MissingRendererDependencyException extends RuntimeException {}
