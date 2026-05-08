<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Feature\Api;

use Illuminate\Foundation\Application;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class CapabilitiesApiEnabledTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('patent-box-tracker.api.enabled', true);
        $app['config']->set('patent-box-tracker.api.middleware', []);
    }

    public function test_capabilities_endpoint_returns_expected_shape(): void
    {
        $response = $this->getJson('/api/patent-box/v1/capabilities')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'package' => ['name', 'api_version'],
                    'roles',
                    'regimes',
                    'render_formats',
                    'locales',
                    'classifier' => ['provider', 'model', 'seed', 'batch_size', 'cost_cap_eur_per_run'],
                    'renderer' => ['driver', 'available_drivers'],
                ],
            ]);

        $response->assertJsonPath('data.package.name', 'padosoft/laravel-patent-box-tracker');
        $response->assertJsonPath('data.package.api_version', 'v1');
        $response->assertJsonPath('data.classifier.provider', 'regolo');
        $response->assertJsonPath('data.classifier.model', 'claude-sonnet-4-6');
    }
}
