<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Feature\Api;

use Illuminate\Foundation\Application;
use Padosoft\PatentBoxTracker\Models\TrackedDossier;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class DossierDownloadApiTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->runPackageMigrations();
    }

    public function test_download_returns_file_for_matching_session_and_dossier(): void
    {
        $session = TrackingSession::query()->create([
            'status' => TrackingSession::STATUS_RENDERED,
        ]);
        $tmp = tempnam(sys_get_temp_dir(), 'patent-box-dossier-');
        file_put_contents($tmp, '{"ok":true}');

        $dossier = TrackedDossier::query()->create([
            'tracking_session_id' => $session->id,
            'format' => 'json',
            'locale' => 'it',
            'path' => $tmp,
            'byte_size' => 11,
            'sha256' => hash('sha256', '{"ok":true}'),
            'generated_at' => now(),
        ]);

        $this->get('/api/patent-box/v1/tracking-sessions/'.$session->id.'/dossiers/'.$dossier->id.'/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Content-Disposition');
    }

    public function test_download_returns_404_when_dossier_belongs_to_another_session(): void
    {
        $sessionA = TrackingSession::query()->create(['status' => TrackingSession::STATUS_RENDERED]);
        $sessionB = TrackingSession::query()->create(['status' => TrackingSession::STATUS_RENDERED]);
        $tmp = tempnam(sys_get_temp_dir(), 'patent-box-dossier-');
        file_put_contents($tmp, '{"ok":true}');

        $dossier = TrackedDossier::query()->create([
            'tracking_session_id' => $sessionA->id,
            'format' => 'json',
            'locale' => 'it',
            'path' => $tmp,
            'byte_size' => 11,
            'sha256' => hash('sha256', '{"ok":true}'),
            'generated_at' => now(),
        ]);

        $this->get('/api/patent-box/v1/tracking-sessions/'.$sessionB->id.'/dossiers/'.$dossier->id.'/download')
            ->assertNotFound();
    }

    public function test_download_returns_404_when_hash_or_size_does_not_match_recorded_integrity(): void
    {
        $session = TrackingSession::query()->create(['status' => TrackingSession::STATUS_RENDERED]);
        $tmp = tempnam(sys_get_temp_dir(), 'patent-box-dossier-');
        file_put_contents($tmp, '{"ok":false}');

        $dossier = TrackedDossier::query()->create([
            'tracking_session_id' => $session->id,
            'format' => 'json',
            'locale' => 'it',
            'path' => $tmp,
            'byte_size' => 11,
            'sha256' => hash('sha256', '{"ok":true}'),
            'generated_at' => now(),
        ]);

        $this->get('/api/patent-box/v1/tracking-sessions/'.$session->id.'/dossiers/'.$dossier->id.'/download')
            ->assertNotFound();
    }
}
