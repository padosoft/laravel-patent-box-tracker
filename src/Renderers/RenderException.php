<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Renderers;

use RuntimeException;

/**
 * Thrown when a {@see DossierRenderer} cannot produce its native
 * binary form. Wraps the underlying engine error so call sites
 * (controllers, console commands) can map every renderer failure
 * onto the same exception type.
 *
 * Renderers MUST throw this on every failure path — never return
 * an empty / null body silently (R14).
 */
final class RenderException extends RuntimeException {}
