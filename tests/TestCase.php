<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests;

use Illuminate\Foundation\Application;
use Laravel\Ai\AiServiceProvider;
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
            AiServiceProvider::class,
            PatentBoxTrackerServiceProvider::class,
        ];
    }

    /**
     * Default environment shared across the package's test suite —
     * configures an SQLite in-memory connection and a single
     * Anthropic-shaped laravel/ai provider so the classifier
     * tests can `Http::fake()` the underlying REST call.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        // Ensure the package picks up the SQLite memory connection.
        $app['config']->set('patent-box-tracker.storage.connection', null);

        // Configure laravel/ai with a deterministic test provider —
        // anthropic-shaped because the Anthropic gateway uses
        // `Http::baseUrl(...)` so `Http::fake()` intercepts the
        // 'https://api.anthropic.com/v1/messages' endpoint cleanly.
        $app['config']->set('ai.default', 'anthropic');
        $app['config']->set('ai.providers.anthropic', [
            'driver' => 'anthropic',
            'key' => 'test-key-anthropic',
            'url' => 'https://api.anthropic.com/v1',
        ]);
    }

    /**
     * Run the package migrations against the in-memory SQLite
     * connection. Sub-classes that touch the Eloquent models
     * (TrackingSession, TrackedCommit, ...) call this from setUp.
     */
    protected function runPackageMigrations(): void
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->run();
    }
}
