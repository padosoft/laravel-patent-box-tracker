<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker;

use Illuminate\Support\ServiceProvider;
use Padosoft\PatentBoxTracker\Sources\CollectorRegistry;
use Padosoft\PatentBoxTracker\Sources\EvidenceCollector;

final class PatentBoxTrackerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/patent-box-tracker.php',
            'patent-box-tracker'
        );

        $this->app->singleton(CollectorRegistry::class, function ($app): CollectorRegistry {
            /** @var array<int, class-string<EvidenceCollector>> $fqcns */
            $fqcns = (array) ($app['config']->get('patent-box-tracker.collectors') ?? []);

            return new CollectorRegistry($fqcns);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/patent-box-tracker.php' => config_path('patent-box-tracker.php'),
            ], 'patent-box-tracker-config');
        }
    }
}
