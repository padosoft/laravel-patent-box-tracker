<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Feature\Api;

use Illuminate\Foundation\Application;
use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackedDossier;
use Padosoft\PatentBoxTracker\Models\TrackedEvidence;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class TrackingReadApiTest extends TestCase
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
        $this->seedSession();
    }

    public function test_list_tracking_sessions_returns_summary(): void
    {
        $this->getJson('/api/patent-box/v1/tracking-sessions')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', (int) $this->session->id)
            ->assertJsonPath('data.0.summary.commit_count', 2)
            ->assertJsonPath('data.0.summary.qualified_commit_count', 1);
    }

    public function test_list_tracking_sessions_supports_date_range_filter(): void
    {
        $this->getJson('/api/patent-box/v1/tracking-sessions?from=2025-12-31&to=2026-12-31')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/patent-box/v1/tracking-sessions?from=2026-02-01')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_list_tracking_sessions_supports_search(): void
    {
        $this->getJson('/api/patent-box/v1/tracking-sessions?search='.$this->session->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', (int) $this->session->id);
    }

    public function test_show_tracking_session_returns_repositories_and_dossiers(): void
    {
        $this->assertSame(2, TrackedCommit::query()->where('tracking_session_id', $this->session->id)->count());
        $this->assertSame(1, TrackedDossier::query()->where('tracking_session_id', $this->session->id)->count());

        $this->getJson('/api/patent-box/v1/tracking-sessions/'.$this->session->id)
            ->assertOk()
            ->assertJsonPath('data.id', (int) $this->session->id)
            ->assertJsonPath('data.repositories.0.role', 'primary_ip')
            ->assertJsonPath('data.dossiers.0.format', 'json');
    }

    public function test_list_commits_supports_phase_filter(): void
    {
        $this->assertSame(1, TrackedCommit::query()
            ->where('tracking_session_id', $this->session->id)
            ->where('phase', 'implementation')
            ->count());

        $this->getJson('/api/patent-box/v1/tracking-sessions/'.$this->session->id.'/commits?phase=implementation')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.phase', 'implementation');
    }

    public function test_list_commits_supports_ai_attribution_filter(): void
    {
        $this->getJson('/api/patent-box/v1/tracking-sessions/'.$this->session->id.'/commits?ai_attribution=human')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ai_attribution', 'human');
    }

    public function test_list_commits_ignores_invalid_boolean_filter_value(): void
    {
        $this->getJson('/api/patent-box/v1/tracking-sessions/'.$this->session->id.'/commits?is_rd_qualified=not-a-bool')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_list_commits_supports_confidence_range_filters(): void
    {
        $this->getJson('/api/patent-box/v1/tracking-sessions/'.$this->session->id.'/commits?rd_confidence_min=0.9&rd_confidence_max=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.rd_qualification_confidence', 0.91);
    }

    public function test_list_commits_search_by_message_subject(): void
    {
        $this->getJson('/api/patent-box/v1/tracking-sessions/'.$this->session->id.'/commits?search=first')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.message_subject', 'first commit');
    }

    public function test_list_evidence_returns_rows(): void
    {
        $this->assertSame(1, TrackedEvidence::query()->where('tracking_session_id', $this->session->id)->count());

        $this->getJson('/api/patent-box/v1/tracking-sessions/'.$this->session->id.'/evidence')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'plan:PLAN-W4');
    }

    public function test_list_evidence_supports_path_like_filter(): void
    {
        $this->getJson('/api/patent-box/v1/tracking-sessions/'.$this->session->id.'/evidence?path_like=PLAN')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.path', 'docs/PLAN-W4.md');
    }

    public function test_list_evidence_supports_search(): void
    {
        $this->getJson('/api/patent-box/v1/tracking-sessions/'.$this->session->id.'/evidence?search=Plan')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Plan W4');
    }

    public function test_list_dossiers_returns_rows(): void
    {
        $this->assertSame(1, TrackedDossier::query()->where('tracking_session_id', $this->session->id)->count());

        $this->getJson('/api/patent-box/v1/tracking-sessions/'.$this->session->id.'/dossiers')
            ->assertOk()
            ->assertJsonPath('data.0.tracking_session_id', (int) $this->session->id)
            ->assertJsonPath('data.0.sha256', str_repeat('a', 64))
            ->assertJsonPath('meta.total', 1);
    }

    public function test_show_dossier_returns_metadata_for_matching_session(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'patent-box-dossier-show-');
        file_put_contents($tmp, '{"ok":true}');

        try {
            $dossier = TrackedDossier::query()->create([
                'tracking_session_id' => $this->session->id,
                'format' => 'json',
                'locale' => 'it',
                'path' => $tmp,
                'byte_size' => 11,
                'sha256' => hash('sha256', '{"ok":true}'),
                'generated_at' => '2026-05-07 10:35:00',
            ]);

            $this->getJson('/api/patent-box/v1/tracking-sessions/'.$this->session->id.'/dossiers/'.$dossier->id)
                ->assertOk()
                ->assertJsonPath('data.id', (int) $dossier->id)
                ->assertJsonPath('data.tracking_session_id', (int) $this->session->id)
                ->assertJsonPath('data.format', 'json');
        } finally {
            if (is_string($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function test_show_dossier_returns_not_found_when_dossier_belongs_to_another_session(): void
    {
        $otherSession = TrackingSession::query()->create(['status' => TrackingSession::STATUS_RENDERED]);
        $tmp = tempnam(sys_get_temp_dir(), 'patent-box-dossier-show-');
        file_put_contents($tmp, '{"ok":true}');

        try {
            $dossier = TrackedDossier::query()->create([
                'tracking_session_id' => $otherSession->id,
                'format' => 'json',
                'locale' => 'it',
                'path' => $tmp,
                'byte_size' => 11,
                'sha256' => hash('sha256', '{"ok":true}'),
                'generated_at' => now(),
            ]);

            $this->getJson('/api/patent-box/v1/tracking-sessions/'.$this->session->id.'/dossiers/'.$dossier->id)
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'not_found');
        } finally {
            if (is_string($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function test_integrity_endpoint_reports_verified_chain(): void
    {
        $this->assertSame(2, TrackedCommit::query()->where('tracking_session_id', $this->session->id)->count());

        $this->getJson('/api/patent-box/v1/tracking-sessions/'.$this->session->id.'/integrity')
            ->assertOk()
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.commit_count', 2)
            ->assertJsonPath('data.first_failure', null);
    }

    private function seedSession(): void
    {
        $this->session = TrackingSession::query()->create([
            'tax_identity_json' => [
                'denomination' => 'Padosoft',
                'p_iva' => 'IT00000000000',
                'fiscal_year' => '2026',
                'regime' => 'documentazione_idonea',
            ],
            'period_from' => '2026-01-01 00:00:00',
            'period_to' => '2026-12-31 00:00:00',
            'classifier_provider' => 'regolo',
            'classifier_model' => 'claude-sonnet-4-6',
            'classifier_seed' => 1,
            'status' => TrackingSession::STATUS_CLASSIFIED,
            'cost_eur_projected' => 12.34,
            'cost_eur_actual' => 12.34,
            'finished_at' => '2026-05-07 10:00:00',
        ]);

        $sha1 = str_repeat('1', 40);
        $sha2 = str_repeat('2', 40);
        $hash1 = hash('sha256', ':'.$sha1);
        $hash2 = hash('sha256', $hash1.':'.$sha2);

        TrackedCommit::query()->create([
            'tracking_session_id' => $this->session->id,
            'repository_path' => '/repo/main',
            'repository_role' => 'primary_ip',
            'sha' => $sha1,
            'author_name' => 'Dev A',
            'author_email' => 'a@example.test',
            'committed_at' => '2026-02-01 10:00:00',
            'message' => 'first commit',
            'phase' => 'implementation',
            'ai_attribution' => 'human',
            'is_rd_qualified' => true,
            'rd_qualification_confidence' => 0.91,
            'evidence_used_json' => ['plan:PLAN-W4'],
            'hash_chain_prev' => null,
            'hash_chain_self' => $hash1,
        ]);

        TrackedCommit::query()->create([
            'tracking_session_id' => $this->session->id,
            'repository_path' => '/repo/main',
            'repository_role' => 'primary_ip',
            'sha' => $sha2,
            'author_name' => 'Dev B',
            'author_email' => 'b@example.test',
            'committed_at' => '2026-02-02 10:00:00',
            'message' => 'second commit',
            'phase' => 'documentation',
            'ai_attribution' => 'ai_assisted',
            'is_rd_qualified' => false,
            'rd_qualification_confidence' => 0.41,
            'evidence_used_json' => [],
            'hash_chain_prev' => $hash1,
            'hash_chain_self' => $hash2,
        ]);

        TrackedEvidence::query()->create([
            'tracking_session_id' => $this->session->id,
            'kind' => 'plan',
            'path' => 'docs/PLAN-W4.md',
            'slug' => 'plan:PLAN-W4',
            'title' => 'Plan W4',
            'first_seen_at' => '2026-01-01 00:00:00',
            'last_modified_at' => '2026-02-01 00:00:00',
            'linked_commit_count' => 1,
        ]);

        TrackedDossier::query()->create([
            'tracking_session_id' => $this->session->id,
            'format' => 'json',
            'locale' => 'it',
            'path' => 'storage/dossiers/1.json',
            'byte_size' => 1024,
            'sha256' => str_repeat('a', 64),
            'generated_at' => '2026-05-07 10:30:00',
        ]);
    }
}
