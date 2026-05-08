<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Feature\Api;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class ApiRateLimitTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('patent-box-tracker.api.enabled', true);
        $app['config']->set('patent-box-tracker.api.middleware', []);
        $app['config']->set('patent-box-tracker.api.rate_limiter', 'api-test-security-hardening');

        RateLimiter::for('api-test-security-hardening', function (Request $request): Limit {
            return Limit::perMinute(1)->by((string) $request->ip());
        });
    }

    public function test_api_respects_configured_throttle_rate_limit(): void
    {
        $this->getJson('/api/patent-box/v1/health')->assertOk();

        $this->getJson('/api/patent-box/v1/health')
            ->assertStatus(429)
            ->assertSee('Too Many Attempts');
    }
}
