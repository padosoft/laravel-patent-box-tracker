<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Feature\Api;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Bus;
use Padosoft\PatentBoxTracker\Jobs\RenderTrackingSessionDossierJob;
use Padosoft\PatentBoxTracker\Jobs\RunTrackingSessionJob;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class TrackingWriteApiTest extends TestCase
{
    private const FIXTURE_REPO = __DIR__.'/../../fixtures/repos/synthetic-r-and-d.git';

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

        if (! is_dir(self::FIXTURE_REPO)) {
            $this->markTestSkipped('Synthetic git fixture not built. Run tests/fixtures/repos/build-synthetic.sh first.');
        }
    }

    public function test_validate_repository_endpoint_returns_commit_count(): void
    {
        $this->postJson('/api/patent-box/v1/repositories/validate', [
            'path' => self::FIXTURE_REPO,
            'role' => 'primary_ip',
            'period' => [
                'from' => '2026-01-01',
                'to' => '2026-12-31',
            ],
        ])->assertOk()
            ->assertJsonPath('data.path', self::FIXTURE_REPO)
            ->assertJsonPath('data.role', 'primary_ip')
            ->assertJsonPath('data.is_git_repository', true);
    }

    public function test_validate_repository_returns_standard_error_shape_on_invalid_payload(): void
    {
        $this->postJson('/api/patent-box/v1/repositories/validate', [])
            ->assertStatus(422)
            ->assertJsonStructure([
                'error' => ['code', 'message', 'details'],
            ])
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_dry_run_returns_projection_shape(): void
    {
        $this->postJson('/api/patent-box/v1/tracking-sessions/dry-run', [
            'mode' => 'single_repo',
            'period' => [
                'from' => '2026-01-01',
                'to' => '2026-12-31',
            ],
            'classifier' => [
                'provider' => 'regolo',
                'model' => 'claude-sonnet-4-6',
            ],
            'repositories' => [
                [
                    'path' => self::FIXTURE_REPO,
                    'role' => 'primary_ip',
                ],
            ],
        ])->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'mode',
                    'total_commit_count',
                    'projected_cost_eur',
                    'cost_cap_eur',
                    'exceeds_cost_cap',
                    'repositories',
                ],
            ]);
    }

    public function test_dry_run_returns_standard_error_shape_on_inverted_period(): void
    {
        $this->postJson('/api/patent-box/v1/tracking-sessions/dry-run', [
            'mode' => 'single_repo',
            'period' => [
                'from' => '2026-12-31',
                'to' => '2026-01-01',
            ],
            'classifier' => [
                'provider' => 'regolo',
                'model' => 'claude-sonnet-4-6',
            ],
            'repositories' => [
                [
                    'path' => self::FIXTURE_REPO,
                    'role' => 'primary_ip',
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_dry_run_cross_repo_requires_primary_ip_repository(): void
    {
        $this->postJson('/api/patent-box/v1/tracking-sessions/dry-run', [
            'mode' => 'cross_repo',
            'period' => [
                'from' => '2026-01-01',
                'to' => '2026-12-31',
            ],
            'repositories' => [
                [
                    'path' => self::FIXTURE_REPO,
                    'role' => 'support',
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_create_tracking_session_dispatches_job(): void
    {
        Bus::fake();

        $response = $this->postJson('/api/patent-box/v1/tracking-sessions', [
            'mode' => 'single_repo',
            'tax_identity' => [
                'denomination' => 'Padosoft',
                'p_iva' => 'IT00000000000',
                'fiscal_year' => '2026',
                'regime' => 'documentazione_idonea',
            ],
            'period' => [
                'from' => '2026-01-01',
                'to' => '2026-12-31',
            ],
            'classifier' => [
                'provider' => 'regolo',
                'model' => 'claude-sonnet-4-6',
            ],
            'repositories' => [
                [
                    'path' => self::FIXTURE_REPO,
                    'role' => 'primary_ip',
                ],
            ],
        ])->assertStatus(202)
            ->assertJsonPath('data.status', TrackingSession::STATUS_QUEUED);

        $sessionId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $sessionId);
        $this->assertDatabaseHas('tracking_sessions', [
            'id' => $sessionId,
            'status' => TrackingSession::STATUS_QUEUED,
        ]);

        Bus::assertDispatched(RunTrackingSessionJob::class, static function (RunTrackingSessionJob $job) use ($sessionId): bool {
            return $job->sessionId === $sessionId;
        });
    }

    public function test_queue_render_dossier_dispatches_job(): void
    {
        Bus::fake();

        $session = TrackingSession::query()->create([
            'tax_identity_json' => [
                'denomination' => 'Padosoft',
                'p_iva' => 'IT00000000000',
                'fiscal_year' => '2026',
                'regime' => 'documentazione_idonea',
            ],
            'period_from' => '2026-01-01 00:00:00',
            'period_to' => '2026-12-31 00:00:00',
            'status' => TrackingSession::STATUS_CLASSIFIED,
        ]);

        $this->postJson('/api/patent-box/v1/tracking-sessions/'.$session->id.'/dossiers', [
            'format' => 'json',
            'locale' => 'it',
        ])->assertStatus(202)
            ->assertJsonPath('data.tracking_session_id', (int) $session->id)
            ->assertJsonPath('data.format', 'json');

        Bus::assertDispatched(RenderTrackingSessionDossierJob::class, static function (RenderTrackingSessionDossierJob $job) use ($session): bool {
            return $job->sessionId === (int) $session->id
                && $job->format === 'json'
                && $job->locale === 'it';
        });
    }

    public function test_queue_render_dossier_requires_renderable_session(): void
    {
        Bus::fake();

        $session = TrackingSession::query()->create([
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

        $this->postJson('/api/patent-box/v1/tracking-sessions/'.$session->id.'/dossiers', [
            'format' => 'json',
            'locale' => 'it',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'conflict');

        Bus::assertNotDispatched(RenderTrackingSessionDossierJob::class);
    }
}
