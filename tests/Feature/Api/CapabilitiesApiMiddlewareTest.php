<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Feature\Api;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class CapabilitiesApiMiddlewareTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('patent-box-tracker.api.enabled', true);
        $app['config']->set('patent-box-tracker.api.middleware', [RejectAllApiMiddleware::class]);
    }

    public function test_capabilities_endpoint_honors_configured_middleware(): void
    {
        $this->getJson('/api/patent-box/v1/capabilities')
            ->assertStatus(401)
            ->assertExactJson([
                'message' => 'Unauthorized.',
            ]);
    }
}

final class RejectAllApiMiddleware
{
    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        return response()->json([
            'message' => 'Unauthorized.',
        ], 401);
    }
}

