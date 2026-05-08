<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Feature\Api;

use Illuminate\Foundation\Application;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class ApiAuthGateTest extends TestCase
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

    public function test_api_is_public_when_token_is_not_configured(): void
    {
        $this->app['config']->set('patent-box-tracker.api.auth_token', '');

        $this->getJson('/api/patent-box/v1/health')->assertOk();
    }

    public function test_api_rejects_request_without_token_when_configured(): void
    {
        $this->app['config']->set('patent-box-tracker.api.auth_token', 'open-sesame');

        $this->getJson('/api/patent-box/v1/health')
            ->assertStatus(401)
            ->assertJsonStructure([
                'error' => ['code', 'message', 'details'],
            ])
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_api_accepts_header_token(): void
    {
        $this->app['config']->set('patent-box-tracker.api.auth_token', 'open-sesame');

        $this->withHeaders(['X-Patent-Box-Api-Key' => 'open-sesame'])
            ->getJson('/api/patent-box/v1/health')
            ->assertOk();
    }

    public function test_api_accepts_bearer_token(): void
    {
        $this->app['config']->set('patent-box-tracker.api.auth_token', 'open-sesame');

        $this->withHeaders(['Authorization' => 'Bearer open-sesame'])
            ->getJson('/api/patent-box/v1/health')
            ->assertOk();
    }
}
