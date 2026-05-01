<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker;

use Illuminate\Support\ServiceProvider;
use Padosoft\PatentBoxTracker\Classifier\ClassifierBatcher;
use Padosoft\PatentBoxTracker\Classifier\ClassifierPrompts;
use Padosoft\PatentBoxTracker\Classifier\CommitClassifier;
use Padosoft\PatentBoxTracker\Classifier\CostCapGuard;
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

        $this->app->singleton(ClassifierPrompts::class, fn (): ClassifierPrompts => new ClassifierPrompts);
        $this->app->singleton(CostCapGuard::class, fn (): CostCapGuard => new CostCapGuard);

        $this->app->bind(CommitClassifier::class, function ($app): CommitClassifier {
            $config = (array) $app['config']->get('patent-box-tracker.classifier', []);

            return new CommitClassifier(
                prompts: $app->make(ClassifierPrompts::class),
                driver: (string) ($config['driver'] ?? 'anthropic'),
                model: (string) ($config['model'] ?? 'claude-sonnet-4-6'),
                seed: (int) ($config['seed'] ?? 0),
                timeoutSeconds: (int) ($config['timeout'] ?? 60),
            );
        });

        $this->app->bind(ClassifierBatcher::class, function ($app): ClassifierBatcher {
            $config = (array) $app['config']->get('patent-box-tracker.classifier', []);

            return new ClassifierBatcher(
                classifier: $app->make(CommitClassifier::class),
                batchSize: (int) ($config['batch_size'] ?? 20),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/patent-box-tracker.php' => config_path('patent-box-tracker.php'),
            ], 'patent-box-tracker-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'patent-box-tracker-migrations');
        }
    }
}
