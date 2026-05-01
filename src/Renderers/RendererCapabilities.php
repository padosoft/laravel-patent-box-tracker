<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Renderers;

/**
 * Reports which optional PDF engines are available in the running
 * environment.
 *
 * Extracted as a separate object so tests can swap an implementation
 * that simulates "no engines installed" without unloading classes
 * from the autoloader (which is brittle across PHPUnit isolation
 * modes). Production code uses {@see DefaultRendererCapabilities};
 * tests can drop in a stub.
 */
interface RendererCapabilities
{
    public function browsershotAvailable(): bool;

    public function dompdfAvailable(): bool;
}
