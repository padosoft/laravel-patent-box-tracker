<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Padosoft\PatentBoxTracker\PatentBoxTrackerServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PatentBoxTrackerServiceProvider::class,
        ];
    }
}
