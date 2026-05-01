<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Renderers;

/**
 * Production implementation of {@see RendererCapabilities} —
 * reports availability via `class_exists()` against the optional
 * Browsershot / DomPDF FQCNs.
 */
final class DefaultRendererCapabilities implements RendererCapabilities
{
    public function browsershotAvailable(): bool
    {
        return class_exists('\\Spatie\\Browsershot\\Browsershot');
    }

    public function dompdfAvailable(): bool
    {
        return class_exists('\\Dompdf\\Dompdf');
    }
}
