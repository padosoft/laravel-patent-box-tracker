<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Renderers;

use Padosoft\PatentBoxTracker\Console\RenderCommand;

/**
 * Thrown when neither Browsershot nor DomPDF is installed in the
 * consumer environment yet a PDF render was requested. Both engines
 * are declared under `require-dev` and `suggest` on the package so
 * consumers can choose their PDF stack — but the JSON sidecar
 * pathway works without either.
 *
 * The exception message lists the install commands so the consumer
 * can recover without grepping the README.
 *
 * Extends {@see RenderException} so {@see RenderCommand}
 * (and other callers) can catch all renderer failures with a single
 * `catch (RenderException)` block without a separate branch for missing
 * dependencies.
 */
final class MissingRendererDependencyException extends RenderException
{
}
