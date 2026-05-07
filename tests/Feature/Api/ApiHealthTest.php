<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Feature\Api;

use Illuminate\Foundation\Application;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class ApiHealthTest extends TestCase
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

    public function test_health_endpoint_works_when_api_enabled(): void
    {
        $this->getJson('/api/patent-box/v1/health')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['status', 'version'],
            ])
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.version', 'v1');
    }
}
