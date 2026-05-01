<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker;

use Illuminate\Support\ServiceProvider;

final class PatentBoxTrackerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/patent-box-tracker.php',
            'patent-box-tracker'
        );
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
