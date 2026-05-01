<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\ServiceProvider;
use Padosoft\PatentBoxTracker\Classifier\ClassifierBatcher;
use Padosoft\PatentBoxTracker\Classifier\ClassifierPrompts;
use Padosoft\PatentBoxTracker\Classifier\CommitClassifier;
use Padosoft\PatentBoxTracker\Classifier\CostCapGuard;
use Padosoft\PatentBoxTracker\Config\CrossRepoConfigValidator;
use Padosoft\PatentBoxTracker\Console\CrossRepoCommand;
use Padosoft\PatentBoxTracker\Console\RenderCommand;
use Padosoft\PatentBoxTracker\Console\TrackCommand;
use Padosoft\PatentBoxTracker\Hash\HashChainBuilder;
use Padosoft\PatentBoxTracker\Renderers\DefaultRendererCapabilities;
use Padosoft\PatentBoxTracker\Renderers\DossierPayloadAssembler;
use Padosoft\PatentBoxTracker\Renderers\JsonDossierRenderer;
use Padosoft\PatentBoxTracker\Renderers\PdfDossierRenderer;
use Padosoft\PatentBoxTracker\Renderers\RendererCapabilities;
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
        $this->app->singleton(CrossRepoConfigValidator::class, fn (): CrossRepoConfigValidator => new CrossRepoConfigValidator);
        $this->app->singleton(HashChainBuilder::class, fn (): HashChainBuilder => new HashChainBuilder);
        $this->app->singleton(RendererCapabilities::class, fn (): RendererCapabilities => new DefaultRendererCapabilities);

        $this->app->singleton(DossierPayloadAssembler::class, function ($app): DossierPayloadAssembler {
            return new DossierPayloadAssembler($app->make(HashChainBuilder::class));
        });

        $this->app->bind(JsonDossierRenderer::class, function ($app): JsonDossierRenderer {
            $locale = (string) ($app['config']->get('patent-box-tracker.locale') ?? 'it');

            return new JsonDossierRenderer(
                assembler: $app->make(DossierPayloadAssembler::class),
                locale: $locale,
            );
        });

        $this->app->bind(PdfDossierRenderer::class, function ($app): PdfDossierRenderer {
            $locale = (string) ($app['config']->get('patent-box-tracker.locale') ?? 'it');
            $rendererConfig = (array) ($app['config']->get('patent-box-tracker.renderer') ?? []);

            return new PdfDossierRenderer(
                assembler: $app->make(DossierPayloadAssembler::class),
                viewFactory: $app->make(ViewFactory::class),
                capabilities: $app->make(RendererCapabilities::class),
                config: $rendererConfig,
                locale: $locale,
            );
        });

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
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'patent-box-tracker');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/patent-box-tracker.php' => config_path('patent-box-tracker.php'),
            ], 'patent-box-tracker-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'patent-box-tracker-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/patent-box-tracker'),
            ], 'patent-box-tracker-views');

            $this->commands([
                RenderCommand::class,
                TrackCommand::class,
                CrossRepoCommand::class,
            ]);
        }
    }
}
