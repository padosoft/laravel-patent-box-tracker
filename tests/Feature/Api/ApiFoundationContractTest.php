<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Feature\Api;

use Illuminate\Foundation\Application;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class ApiFoundationContractTest extends TestCase
{
    private TrackingSession $session;

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('patent-box-tracker.api.enabled', true);
        $app['config']->set('patent-box-tracker.api.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->runPackageMigrations();

        $this->session = TrackingSession::query()->create([
            'tax_identity_json' => [
                'denomination' => 'Padosoft',
                'p_iva' => 'IT00000000000',
                'fiscal_year' => '2026',
                'regime' => 'documentazione_idonea',
            ],
            'period_from' => '2026-01-01 00:00:00',
            'period_to' => '2026-12-31 00:00:00',
            'status' => TrackingSession::STATUS_PENDING,
        ]);
    }

    public function test_foundation_endpoints_follow_envelope_contract(): void
    {
        $sessionId = $this->session->id;
        $this->getJson('/api/patent-box/v1/health')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson('/api/patent-box/v1/capabilities')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson('/api/patent-box/v1/tracking-sessions')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson('/api/patent-box/v1/tracking-sessions/'.$sessionId)
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson('/api/patent-box/v1/tracking-sessions/'.$sessionId.'/commits')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);

        $this->getJson('/api/patent-box/v1/tracking-sessions/'.$sessionId.'/evidence')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);

        $this->getJson('/api/patent-box/v1/tracking-sessions/'.$sessionId.'/dossiers')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson('/api/patent-box/v1/tracking-sessions/'.$sessionId.'/integrity')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_not_found_routes_return_standard_error_contract(): void
    {
        $this->getJson('/api/patent-box/v1/tracking-sessions/999999')
            ->assertNotFound()
            ->assertJsonStructure([
                'error' => ['code', 'message', 'details'],
            ])
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_validation_errors_return_standard_error_contract(): void
    {
        $this->postJson('/api/patent-box/v1/tracking-sessions', [])
            ->assertStatus(422)
            ->assertJsonStructure([
                'error' => ['code', 'message', 'details'],
            ])
            ->assertJsonPath('error.code', 'validation_failed');
    }
}
