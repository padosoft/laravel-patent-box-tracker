<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit;

use Padosoft\PatentBoxTracker\PatentBoxTrackerServiceProvider;
use Padosoft\PatentBoxTracker\Sources\CollectorRegistry;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class ServiceProviderBootsTest extends TestCase
{
    public function test_service_provider_boots_under_testbench(): void
    {
        $providers = $this->app->getLoadedProviders();

        $this->assertArrayHasKey(
            PatentBoxTrackerServiceProvider::class,
            $providers,
            'PatentBoxTrackerServiceProvider should be loaded by Testbench.'
        );
    }

    public function test_default_config_is_published_into_the_container(): void
    {
        $config = $this->app->make('config');

        $this->assertSame(
            'regolo',
            $config->get('patent-box-tracker.classifier.driver'),
            'Default classifier driver should be "regolo".'
        );

        $this->assertSame(
            'documentazione_idonea',
            $config->get('patent-box-tracker.regime'),
            'Default fiscal regime should be "documentazione_idonea".'
        );

        $this->assertSame(
            'it',
            $config->get('patent-box-tracker.locale'),
            'Default dossier locale should be Italian for v0.1.'
        );
    }

    public function test_collector_registry_is_singleton_with_four_collectors_configured(): void
    {
        /** @var CollectorRegistry $registry */
        $registry = $this->app->make(CollectorRegistry::class);

        $this->assertInstanceOf(CollectorRegistry::class, $registry);
        $this->assertSame(
            $registry,
            $this->app->make(CollectorRegistry::class),
            'CollectorRegistry must be registered as a singleton.',
        );

        // Boot-time validation passes (no exception) and loads four
        // canonical collectors per config('patent-box-tracker.collectors').
        $this->assertCount(4, $registry->collectors());
    }

    public function test_classifier_uses_deterministic_seed_by_default(): void
    {
        $config = $this->app->make('config');

        $this->assertSame(
            0,
            $config->get('patent-box-tracker.classifier.temperature'),
            'Classifier temperature must be 0 so re-running on the same '
            .'commit produces an identical classification — auditors rely on '
            .'reproducibility.'
        );

        $this->assertIsInt(
            $config->get('patent-box-tracker.classifier.seed'),
            'Classifier seed must be a non-null integer.'
        );
    }
}
