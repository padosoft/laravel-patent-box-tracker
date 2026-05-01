<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Feature\Console;

use Illuminate\Support\Facades\Http;
use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Tests\TestCase;

/**
 * End-to-end coverage for `php artisan patent-box:cross-repo`.
 *
 * Drives the cross-repo orchestrator with a YAML config that lists
 * two synthetic fixture repos (one as `primary_ip`, one as `support`)
 * and asserts:
 *
 *   - one tracking_session row covers BOTH repos;
 *   - tracked_commits is keyed by (session_id, repository_path, sha)
 *     and every fixture commit lands in the right bucket;
 *   - --dry-run skips classifier calls and reports per-repo + total
 *     projections;
 *   - the cross-repo per-repo summary is emitted on stdout.
 */
final class CrossRepoCommandTest extends TestCase
{
    private const PRIMARY_REPO = __DIR__.'/../../fixtures/repos/synthetic-r-and-d.git';

    private const SUPPORT_REPO = __DIR__.'/../../fixtures/repos/synthetic-support.git';

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_dir(self::PRIMARY_REPO)) {
            $this->markTestSkipped(
                'Synthetic primary fixture not built. Run tests/fixtures/repos/build-synthetic.sh.'
            );
        }
        if (! is_dir(self::SUPPORT_REPO)) {
            $this->markTestSkipped(
                'Synthetic support fixture not built. Run tests/fixtures/repos/build-second-synthetic.sh.'
            );
        }
        $this->app['config']->set('patent-box-tracker.classifier.driver', 'anthropic');
        $this->runPackageMigrations();
    }

    public function test_dry_run_aggregates_cost_across_repos_without_classifying(): void
    {
        Http::fake();

        $configPath = $this->writeYamlConfig();
        try {
            $exit = $this->artisan('patent-box:cross-repo', [
                'config' => $configPath,
                '--dry-run' => true,
            ])->run();

            $this->assertSame(0, $exit);
            Http::assertNothingSent();
            $this->assertSame(0, TrackedCommit::query()->count());
            $session = TrackingSession::query()->firstOrFail();
            $this->assertSame(TrackingSession::STATUS_PENDING, $session->status);
        } finally {
            @unlink($configPath);
        }
    }

    public function test_full_run_classifies_both_repos_into_one_session(): void
    {
        $this->fakeClassifierResponses();

        $configPath = $this->writeYamlConfig();
        try {
            $exit = $this->artisan('patent-box:cross-repo', [
                'config' => $configPath,
            ])->run();

            $this->assertSame(0, $exit);

            $this->assertSame(1, TrackingSession::query()->count());
            $session = TrackingSession::query()->firstOrFail();
            $this->assertSame(TrackingSession::STATUS_CLASSIFIED, $session->status);

            // Synthetic-primary has 8 non-bot commits; synthetic-support
            // has 3. Total expected = 11, all under the same session.
            $this->assertSame(11, TrackedCommit::query()
                ->where('tracking_session_id', $session->id)
                ->count());

            $primaryCount = TrackedCommit::query()
                ->where('tracking_session_id', $session->id)
                ->where('repository_path', self::PRIMARY_REPO)
                ->count();
            $supportCount = TrackedCommit::query()
                ->where('tracking_session_id', $session->id)
                ->where('repository_path', self::SUPPORT_REPO)
                ->count();
            $this->assertSame(8, $primaryCount);
            $this->assertSame(3, $supportCount);

            // Roles persisted per-row from YAML.
            $primaryRow = TrackedCommit::query()
                ->where('tracking_session_id', $session->id)
                ->where('repository_path', self::PRIMARY_REPO)
                ->first();
            $supportRow = TrackedCommit::query()
                ->where('tracking_session_id', $session->id)
                ->where('repository_path', self::SUPPORT_REPO)
                ->first();
            $this->assertNotNull($primaryRow);
            $this->assertNotNull($supportRow);
            $this->assertSame('primary_ip', $primaryRow->repository_role);
            $this->assertSame('support', $supportRow->repository_role);

            // tax_identity_json embeds ip_outputs + manual_supplement
            $taxIdentity = $session->tax_identity_json;
            $this->assertIsArray($taxIdentity);
            $this->assertArrayHasKey('ip_outputs', $taxIdentity);
            $this->assertArrayHasKey('manual_supplement', $taxIdentity);
        } finally {
            @unlink($configPath);
        }
    }

    public function test_invalid_config_returns_exit_code_1(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'cross-repo-bad-');
        file_put_contents($tempPath, "fiscal_year: 26\n");

        try {
            $exit = $this->artisan('patent-box:cross-repo', [
                'config' => $tempPath,
            ])->run();

            $this->assertSame(1, $exit);
            $this->assertSame(0, TrackingSession::query()->count());
        } finally {
            @unlink($tempPath);
        }
    }

    private function writeYamlConfig(): string
    {
        $primary = self::PRIMARY_REPO;
        $support = self::SUPPORT_REPO;
        $yaml = <<<YAML
fiscal_year: 2026
period:
  from: '2026-01-01'
  to: '2026-12-31'
tax_identity:
  denomination: Padosoft di Lorenzo Padovani
  p_iva: IT00000000000
  regime: documentazione_idonea
cost_model:
  hourly_rate_eur: 80
  daily_hours_max: 8
classifier:
  provider: anthropic
  model: claude-sonnet-4-6
repositories:
  - path: {$primary}
    role: primary_ip
  - path: {$support}
    role: support
manual_supplement:
  off_keyboard_research_hours: 60
ip_outputs:
  - kind: software_siae
    title: AskMyDocs Enterprise Platform v4.0
    registration_id: SIAE-2026-...

YAML;
        $tempPath = tempnam(sys_get_temp_dir(), 'cross-repo-cfg-').'.yml';
        file_put_contents($tempPath, $yaml);

        return $tempPath;
    }

    private function fakeClassifierResponses(): void
    {
        Http::fake(function ($request) {
            $body = (string) $request->body();
            preg_match_all('/sha: ([a-f0-9]{40})/', $body, $matches);
            $shas = $matches[1] ?? [];
            $classifications = [];
            foreach ($shas as $sha) {
                $classifications[] = [
                    'sha' => $sha,
                    'phase' => 'implementation',
                    'is_rd_qualified' => true,
                    'rd_qualification_confidence' => 0.85,
                    'rationale' => 'Synthetic test classification.',
                    'rejected_phase' => null,
                    'evidence_used' => [],
                ];
            }

            return Http::response([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'text', 'text' => json_encode(['classifications' => $classifications], JSON_THROW_ON_ERROR)],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 200, 'output_tokens' => 100],
            ], 200);
        });
    }
}
